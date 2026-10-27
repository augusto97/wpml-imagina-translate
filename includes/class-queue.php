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

    /** A claimed item untouched for this long belongs to a pass that died. */
    const STALE_AFTER = 1800;

    /** Interrupted that many times, an item is failed instead of retried. */
    const MAX_ATTEMPTS = 3;

    /** Finished items are kept this long for the progress view, then removed. */
    const KEEP_FINISHED = WEEK_IN_SECONDS;

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
            if (!$post_id || is_wp_error(WIT_WPML_Integration::instance()->can_translate($post_id, $target_language))) {
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
        if (!$this->acquire_lock()) {
            return; // Another pass is already running.
        }

        @ignore_user_abort(true);
        @set_time_limit(0);

        $this->cleanup();

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
            $this->release_lock();
        }

        if ($this->count_pending() > 0) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
            spawn_cron();
        }
    }

    /**
     * Take the pass lock.
     *
     * An INSERT IGNORE on the options table's unique key: of two passes
     * starting at once, exactly one inserts. Neither get_transient() followed
     * by set_transient() (both read "free" before either writes) nor
     * add_option() (an INSERT ... ON DUPLICATE KEY UPDATE, which "succeeds"
     * for both) excludes anything. A lock older than LOCK_TTL belongs to a
     * pass that died and is taken over.
     *
     * Correctness does not depend on it — claim() is atomic per row — but it
     * keeps passes from piling up on a slow site.
     *
     * @return bool
     */
    private function acquire_lock() {
        global $wpdb;

        $insert = function () use ($wpdb) {
            return (int) $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::LOCK_KEY,
                (string) time()
            )) === 1;
        };

        wp_cache_delete(self::LOCK_KEY, 'options');

        if ($insert()) {
            return true;
        }

        $held_since = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK_KEY
        ));

        if ($held_since > time() - self::LOCK_TTL) {
            return false;
        }

        // Taken over only if nobody else took it over first.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK_KEY,
            (string) $held_since
        ));

        return $insert();
    }

    private function release_lock() {
        global $wpdb;

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_KEY));
        wp_cache_delete(self::LOCK_KEY, 'options');
    }

    /**
     * Claim the next pending rows, one atomic UPDATE per row.
     *
     * The previous version updated the rows as a set and then re-read them by
     * status. A second pass that selected the same rows and lost the race
     * still read them back — "processing" was exactly what the winner had
     * set — and both passes translated them: twice the work, twice the API
     * bill. Here a row belongs to a pass only when that pass's own UPDATE
     * changed it.
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

        $claimed = array();

        foreach ((array) $ids as $id) {
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, attempts = attempts + 1, updated_at = %s
                 WHERE id = %d AND status = %s",
                self::STATUS_PROCESSING,
                current_time('mysql'),
                (int) $id,
                self::STATUS_PENDING
            ));

            if ((int) $changed === 1) {
                $claimed[] = (int) $id;
            }
        }

        if (empty($claimed)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($claimed), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders generated, values bound.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id ASC",
            $claimed
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

        $recent_sql = "SELECT q.id, q.post_id, q.status, q.message, q.target_lang
                       FROM {$table} q {$where}
                       ORDER BY q.updated_at DESC LIMIT 15";

        // phpcs:ignore WordPress.DB.PreparedSQL -- $where is a literal, values bound.
        $recent = $params
            ? $wpdb->get_results($wpdb->prepare($recent_sql, $params), ARRAY_A)
            : $wpdb->get_results($recent_sql, ARRAY_A);

        foreach ($recent as $index => $row) {
            // Titles of other people's drafts and private posts are not an
            // Author's business, even in a progress list.
            if (!current_user_can('edit_post', (int) $row['post_id'])) {
                unset($recent[$index]);
                continue;
            }
            $recent[$index]['id']    = (int) $row['id'];
            $recent[$index]['title'] = get_the_title((int) $row['post_id']);
        }
        $recent = array_values($recent);

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
     * Recover items abandoned by a pass that died, and forget old results.
     *
     * Never called before 1.4.0. A PHP fatal, a host timeout or an out of
     * memory in the middle of an item left it "processing" for ever — and
     * because enqueue() refuses a post already waiting or processing, that
     * post could never be queued for that language again.
     *
     * Times are compared in the same clock they are written in:
     * current_time('mysql'), the site's local time. The earlier draft compared
     * them with gmdate(), which on a site at UTC-5 treated every running item
     * as abandoned.
     *
     * @return array{requeued:int,failed:int,removed:int}
     */
    public function cleanup() {
        global $wpdb;

        $table = self::table();
        $now   = current_time('timestamp');
        $stale = gmdate('Y-m-d H:i:s', $now - self::STALE_AFTER);

        // Interrupted too often: probably the item itself kills the process
        // (a page too large for memory). Fail it so the queue moves on.
        $failed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, message = %s, updated_at = %s
             WHERE status = %s AND updated_at < %s AND attempts >= %d",
            self::STATUS_ERROR,
            __('Se interrumpió varias veces sin terminar (¿tiempo o memoria del servidor?).', 'wpml-imagina-translate'),
            current_time('mysql'),
            self::STATUS_PROCESSING,
            $stale,
            self::MAX_ATTEMPTS
        ));

        $requeued = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s WHERE status = %s AND updated_at < %s",
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            $stale
        ));

        $removed = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN (%s, %s) AND updated_at < %s",
            self::STATUS_DONE,
            self::STATUS_ERROR,
            gmdate('Y-m-d H:i:s', $now - self::KEEP_FINISHED)
        ));

        return array('requeued' => $requeued, 'failed' => $failed, 'removed' => $removed);
    }

    /**
     * Discard what has not run yet.
     *
     * Editors and administrators cancel the whole queue; anyone else only what
     * they queued themselves — an Author must not be able to throw away the
     * site-wide job an administrator started.
     *
     * @return int
     */
    public function cancel_pending() {
        global $wpdb;

        if (current_user_can('edit_others_posts')) {
            return (int) $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::table() . ' WHERE status = %s',
                self::STATUS_PENDING
            ));
        }

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE status = %s AND user_id = %d',
            self::STATUS_PENDING,
            get_current_user_id()
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
