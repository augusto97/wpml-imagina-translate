<?php
/**
 * Queue - background batch translation.
 *
 * The old batch mode looped in the browser: it translated one post, waited for
 * the response, then started the next. Closing the tab stopped the run, and a
 * site with a couple of hundred pages was simply not translatable. The
 * "Tamaño de Lote" setting existed in the UI but was never read by anything.
 *
 * Work is now recorded in a table and drained by WP-Cron, so the operator can
 * queue a whole site and walk away. Each cron pass claims up to `batch_size`
 * pending items, translates them, and reschedules itself while work remains.
 *
 * Two details matter for correctness in cron:
 *
 *  - Cron has no current user. Capabilities are therefore checked when the work
 *    is ENQUEUED, and the enqueuing user is restored while the item runs, which
 *    also keeps Elementor's `is_editable_by_current_user()` check satisfied.
 *  - Overlapping cron passes would translate the same post twice, so a claim is
 *    an atomic UPDATE guarded by a short-lived lock.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIT_Queue {

    const CRON_HOOK   = 'wit_process_queue';
    const LOCK_KEY    = 'wit_queue_lock';
    const LOCK_TTL    = 600;

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_ERROR      = 'error';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(self::CRON_HOOK, array($this, 'process'));
    }

    /**
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wit_queue';
    }

    // -----------------------------------------------------------------------
    // Enqueuing
    // -----------------------------------------------------------------------

    /**
     * Add posts to the queue.
     *
     * Capabilities are verified here, while a real user is present.
     *
     * @param int[]  $post_ids
     * @param string $target_language
     * @return array{batch_id:string,queued:int,skipped:int}
     */
    public function enqueue(array $post_ids, $target_language) {
        global $wpdb;

        $batch_id = wp_generate_uuid4();
        $user_id  = get_current_user_id();
        $now      = current_time('mysql');

        $queued  = 0;
        $skipped = 0;

        foreach (array_unique(array_map('absint', $post_ids)) as $post_id) {
            if (!$post_id || !get_post($post_id) || !current_user_can('edit_post', $post_id)) {
                $skipped++;
                continue;
            }

            // Do not queue the same post and language twice while one is waiting.
            $duplicate = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::table() . '
                 WHERE post_id = %d AND target_lang = %s AND status IN (%s, %s) LIMIT 1',
                $post_id,
                $target_language,
                self::STATUS_PENDING,
                self::STATUS_PROCESSING
            ));

            if ($duplicate) {
                $skipped++;
                continue;
            }

            $inserted = $wpdb->insert(
                self::table(),
                array(
                    'batch_id'    => $batch_id,
                    'post_id'     => $post_id,
                    'target_lang' => $target_language,
                    'status'      => self::STATUS_PENDING,
                    'message'     => '',
                    'attempts'    => 0,
                    'user_id'     => $user_id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array('%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
            );

            if ($inserted) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        if ($queued > 0) {
            $this->schedule();
        }

        return array('batch_id' => $batch_id, 'queued' => $queued, 'skipped' => $skipped);
    }

    /**
     * Ask WP-Cron to drain the queue shortly.
     */
    public function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }

        // WP-Cron only fires on a front-end request. Nudge it so a queue started
        // from the admin does not sit idle on a low-traffic site.
        spawn_cron();
    }

    // -----------------------------------------------------------------------
    // Draining
    // -----------------------------------------------------------------------

    /**
     * Translate the next slice of pending items.
     *
     * Hooked to the cron event; safe to call directly.
     */
    public function process() {
        if (get_transient(self::LOCK_KEY)) {
            return; // Another pass is already running.
        }

        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);

        @ignore_user_abort(true);
        @set_time_limit(0);

        $settings = WIT_Settings::instance()->get_settings();
        $size     = max(1, min(50, (int) $settings['batch_size']));

        $original_user = get_current_user_id();

        try {
            $items = $this->claim($size);

            foreach ($items as $item) {
                $this->run_item($item);
            }
        } catch (\Throwable $e) {
            // Never let one failure wedge the queue.
            error_log('WIT queue: ' . $e->getMessage());
        } finally {
            wp_set_current_user($original_user);
            delete_transient(self::LOCK_KEY);
        }

        if ($this->count_pending() > 0) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
            spawn_cron();
        }
    }

    /**
     * Atomically mark the next pending rows as processing and return them.
     *
     * @param int $limit
     * @return array[]
     */
    private function claim($limit) {
        global $wpdb;

        $table = self::table();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
            self::STATUS_PENDING,
            $limit
        ));

        if (empty($ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // The status check inside the UPDATE is what makes the claim atomic:
        // a concurrent pass that already took these rows changes their status,
        // so this update matches nothing.
        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders generated, values bound.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s
             WHERE id IN ({$placeholders}) AND status = %s",
            array_merge(
                array(self::STATUS_PROCESSING, current_time('mysql')),
                $ids,
                array(self::STATUS_PENDING)
            )
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders generated, values bound.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders}) AND status = %s",
            array_merge($ids, array(self::STATUS_PROCESSING))
        ), ARRAY_A);
    }

    /**
     * Translate one queued item and record the outcome.
     *
     * @param array $item
     */
    private function run_item(array $item) {
        // Restore the user who queued the work: cron has no current user, and
        // Elementor refuses to save a document it thinks nobody may edit.
        wp_set_current_user((int) $item['user_id']);

        $processor = new WIT_Batch_Processor();
        $result    = $processor->process_single((int) $item['post_id'], $item['target_lang']);

        $this->finish(
            (int) $item['id'],
            !empty($result['success']) ? self::STATUS_DONE : self::STATUS_ERROR,
            isset($result['message']) ? (string) $result['message'] : ''
        );
    }

    /**
     * @param int    $id
     * @param string $status
     * @param string $message
     */
    private function finish($id, $status, $message) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'     => $status,
                'message'    => mb_substr($message, 0, 500),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    /**
     * @return int
     */
    public function count_pending() {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s',
            self::STATUS_PENDING
        ));
    }

    /**
     * Progress figures, optionally limited to one batch.
     *
     * @param string $batch_id
     * @return array{pending:int,processing:int,done:int,error:int,total:int,recent:array}
     */
    public function status($batch_id = '') {
        global $wpdb;

        $table = self::table();

        $where  = '';
        $params = array();

        if ($batch_id !== '') {
            $where    = 'WHERE batch_id = %s';
            $params[] = $batch_id;
        }

        $sql = "SELECT status, COUNT(*) AS total FROM {$table} {$where} GROUP BY status";

        // phpcs:ignore WordPress.DB.PreparedSQL -- $where is a literal, values bound.
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        $counts = array(
            self::STATUS_PENDING    => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_DONE       => 0,
            self::STATUS_ERROR      => 0,
        );

        foreach ((array) $rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }

        $recent_sql = "SELECT q.post_id, q.status, q.message, q.target_lang
                       FROM {$table} q {$where}
                       ORDER BY q.updated_at DESC LIMIT 15";

        // phpcs:ignore WordPress.DB.PreparedSQL -- $where is a literal, values bound.
        $recent = $params
            ? $wpdb->get_results($wpdb->prepare($recent_sql, $params), ARRAY_A)
            : $wpdb->get_results($recent_sql, ARRAY_A);

        foreach ($recent as $index => $row) {
            $recent[$index]['title'] = get_the_title((int) $row['post_id']);
        }

        return array(
            'pending'    => $counts[self::STATUS_PENDING],
            'processing' => $counts[self::STATUS_PROCESSING],
            'done'       => $counts[self::STATUS_DONE],
            'error'      => $counts[self::STATUS_ERROR],
            'total'      => array_sum($counts),
            'recent'     => $recent,
        );
    }

    /**
     * Remove finished rows, and requeue anything stuck in processing.
     *
     * @return int Rows removed.
     */
    public function cleanup() {
        global $wpdb;

        $table = self::table();

        // A pass that died mid-item leaves rows claimed forever; give them back.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s WHERE status = %s AND updated_at < %s",
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            gmdate('Y-m-d H:i:s', time() - self::LOCK_TTL * 2)
        ));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN (%s, %s)",
            self::STATUS_DONE,
            self::STATUS_ERROR
        ));
    }

    /**
     * Discard everything that has not run yet.
     *
     * @return int
     */
    public function cancel_pending() {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE status = %s',
            self::STATUS_PENDING
        ));
    }

    /**
     * @return string
     */
    public static function schema() {
        global $wpdb;

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_id char(36) NOT NULL DEFAULT '',
            post_id bigint(20) unsigned NOT NULL,
            target_lang varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            message varchar(500) NOT NULL DEFAULT '',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY batch_id (batch_id),
            KEY post_lang (post_id, target_lang)
        ) {$collate};";
    }
}
