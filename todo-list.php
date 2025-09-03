    <?php
    /**
     * Plugin Name: RToDo — To‑Do List for WordPress
     * Description: A simple, per‑user to‑do list inside the WordPress Dashboard with priorities, statuses, and email reminders.
     * Version: 1.0.0
     * Author: Radwan Rahman (Project: 231-134-009)
     * Text Domain: rtodo
     * Requires at least: 6.0
     * Requires PHP: 7.4
     */

    if ( ! defined( 'ABSPATH' ) ) { exit; }

    define( 'RTODO_VERSION', '1.0.0' );
    define( 'RTODO_PLUGIN_FILE', __FILE__ );
    define( 'RTODO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'RTODO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    global $wpdb;
    define( 'RTODO_TABLE', $wpdb->prefix . 'rtodo_tasks' );

    // Activation: create table + schedule cron
    register_activation_hook( __FILE__, 'rtodo_activate_plugin' );
    
    function rtodo_activate_plugin() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $table_name = $wpdb->prefix . 'rtodo_tasks';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            due_date DATE NULL,
            priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
            status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY due_date (due_date),
            KEY status (status),
            KEY priority (priority)
        ) $charset_collate;";
        
        dbDelta( $sql );
        
        // Schedule daily reminders
        if ( ! wp_next_scheduled( 'rtodo_daily_reminder' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rtodo_daily_reminder' );
        }
    }

    // Deactivation: clear cron (keep data)
    register_deactivation_hook( __FILE__, function() {
        $timestamp = wp_next_scheduled( 'rtodo_daily_reminder' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'rtodo_daily_reminder' );
        }
    } );

    // Admin assets
    add_action( 'admin_enqueue_scripts', function( $hook ) {
        if ( $hook !== 'toplevel_page_rtodo' ) return;
        wp_enqueue_style( 'rtodo-admin', RTODO_PLUGIN_URL . 'assets/css/admin.css', [], RTODO_VERSION );
    } );

    // Admin menu
    add_action( 'admin_menu', function() {
        add_menu_page(
            __( 'To‑Do List', 'rtodo' ),
            __( 'To‑Do List', 'rtodo' ),
            'read',
            'rtodo',
            'rtodo_render_admin_page',
            'dashicons-list-view',
            26
        );
    } );

    // Helpers
    function rtodo_priorities() {
        return [
            'low'    => __( 'Low', 'rtodo' ),
            'medium' => __( 'Medium', 'rtodo' ),
            'high'   => __( 'High', 'rtodo' ),
        ];
    }
    function rtodo_statuses() {
        return [
            'pending'     => __( 'Pending', 'rtodo' ),
            'in_progress' => __( 'In Progress', 'rtodo' ),
            'completed'   => __( 'Completed', 'rtodo' ),
        ];
    }

    // CRUD handlers
    function rtodo_handle_request() {
        if ( ! is_user_logged_in() ) return;
        $user_id = get_current_user_id();
        global $wpdb;

        // Create / Update task
        if ( isset($_POST['rtodo_action']) && in_array($_POST['rtodo_action'], ['create','update'], true) ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'rtodo_save_task' ) ) {
                wp_die( __( 'Security check failed.', 'rtodo' ) );
            }
            if ( ! current_user_can('read') ) {
                wp_die( __( 'You do not have sufficient permissions.', 'rtodo' ) );
            }

            $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
            $description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
            $due_date = sanitize_text_field( $_POST['due_date'] ?? '' );
            $priority = sanitize_text_field( $_POST['priority'] ?? 'medium' );
            $status = sanitize_text_field( $_POST['status'] ?? 'pending' );

            if ( empty( $title ) ) {
                wp_redirect( add_query_arg( 'rtodo_error', 'title_required', admin_url( 'admin.php?page=rtodo' ) ) );
                exit;
            }

            if ( $_POST['rtodo_action'] === 'create' ) {
                $result = $wpdb->insert( RTODO_TABLE, [
                    'user_id'     => $user_id,
                    'title'       => $title,
                    'description' => $description,
                    'due_date'    => $due_date ?: null,
                    'priority'    => in_array($priority, ['low','medium','high'], true) ? $priority : 'medium',
                    'status'      => in_array($status, ['pending','in_progress','completed'], true) ? $status : 'pending',
                    'created_at'  => current_time('mysql'),
                    'updated_at'  => current_time('mysql'),
                ], [ '%d','%s','%s','%s','%s','%s','%s','%s' ] );
                
                if ( $result !== false ) {
                    wp_redirect( add_query_arg( 'rtodo_message', 'created', admin_url( 'admin.php?page=rtodo' ) ) );
                } else {
                    wp_redirect( add_query_arg( 'rtodo_error', 'create_failed', admin_url( 'admin.php?page=rtodo' ) ) );
                }
                exit;
            } else {
                $task_id = absint( $_POST['task_id'] ?? 0 );
                // Ownership check
                $owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM " . RTODO_TABLE . " WHERE id=%d", $task_id ) );
                if ( ! $task_id || intval($owner) !== intval($user_id) ) {
                    wp_redirect( add_query_arg( 'rtodo_error', 'not_allowed', admin_url( 'admin.php?page=rtodo' ) ) );
                    exit;
                }
                $result = $wpdb->update( RTODO_TABLE, [
                    'title'       => $title,
                    'description' => $description,
                    'due_date'    => $due_date ?: null,
                    'priority'    => in_array($priority, ['low','medium','high'], true) ? $priority : 'medium',
                    'status'      => in_array($status, ['pending','in_progress','completed'], true) ? $status : 'pending',
                    'updated_at'  => current_time('mysql'),
                ], [ 'id' => $task_id ], [ '%s','%s','%s','%s','%s','%s' ], [ '%d' ] );
                
                if ( $result !== false ) {
                    wp_redirect( add_query_arg( 'rtodo_message', 'updated', admin_url( 'admin.php?page=rtodo' ) ) );
                } else {
                    wp_redirect( add_query_arg( 'rtodo_error', 'update_failed', admin_url( 'admin.php?page=rtodo' ) ) );
                }
                exit;
            }
        }

        // Delete task
        if ( isset($_GET['rtodo_delete']) ) {
            $task_id = absint( $_GET['rtodo_delete'] );
            if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'rtodo_delete_' . $task_id ) ) {
                wp_die( __( 'Security check failed.', 'rtodo' ) );
            }
            $owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM " . RTODO_TABLE . " WHERE id=%d", $task_id ) );
            if ( $task_id && intval($owner) === intval($user_id) ) {
                $wpdb->delete( RTODO_TABLE, [ 'id' => $task_id ], [ '%d' ] );
                wp_redirect( add_query_arg( 'rtodo_message', 'deleted', admin_url( 'admin.php?page=rtodo' ) ) );
            } else {
                wp_redirect( add_query_arg( 'rtodo_error', 'not_allowed', admin_url( 'admin.php?page=rtodo' ) ) );
            }
            exit;
        }

        // Mark complete
        if ( isset($_GET['rtodo_complete']) ) {
            $task_id = absint( $_GET['rtodo_complete'] );
            if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'rtodo_complete_' . $task_id ) ) {
                wp_die( __( 'Security check failed.', 'rtodo' ) );
            }
            $owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM " . RTODO_TABLE . " WHERE id=%d", $task_id ) );
            if ( $task_id && intval($owner) === intval($user_id) ) {
                $wpdb->update( RTODO_TABLE, [ 'status' => 'completed', 'updated_at' => current_time('mysql') ], [ 'id' => $task_id ], [ '%s','%s' ], [ '%d' ] );
                wp_redirect( add_query_arg( 'rtodo_message', 'completed', admin_url( 'admin.php?page=rtodo' ) ) );
            } else {
                wp_redirect( add_query_arg( 'rtodo_error', 'not_allowed', admin_url( 'admin.php?page=rtodo' ) ) );
            }
            exit;
        }
    }
    add_action( 'admin_init', 'rtodo_handle_request' );

    // Admin page
    function rtodo_render_admin_page() {
        if ( ! current_user_can('read') ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'rtodo' ) );
        }
        $user_id = get_current_user_id();
        global $wpdb;

        // Handle messages from redirects
        if ( isset($_GET['rtodo_message']) ) {
            $message_type = sanitize_text_field( $_GET['rtodo_message'] );
            $messages = [
                'created'   => __( 'Task created successfully.', 'rtodo' ),
                'updated'   => __( 'Task updated successfully.', 'rtodo' ),
                'deleted'   => __( 'Task deleted successfully.', 'rtodo' ),
                'completed' => __( 'Task marked as completed.', 'rtodo' )
            ];
            if ( isset( $messages[$message_type] ) ) {
                add_settings_error( 'rtodo', 'rtodo_success', $messages[$message_type], 'updated' );
            }
        }
        
        if ( isset($_GET['rtodo_error']) ) {
            $error_type = sanitize_text_field( $_GET['rtodo_error'] );
            $errors = [
                'title_required' => __( 'Title is required.', 'rtodo' ),
                'create_failed'  => __( 'Failed to create task.', 'rtodo' ),
                'update_failed'  => __( 'Failed to update task.', 'rtodo' ),
                'not_allowed'    => __( 'You are not allowed to perform this action.', 'rtodo' )
            ];
            if ( isset( $errors[$error_type] ) ) {
                add_settings_error( 'rtodo', 'rtodo_error', $errors[$error_type], 'error' );
            }
        }

        // Fetch tasks for current user
        $where = $wpdb->prepare( "WHERE user_id = %d", $user_id );
        $order = "ORDER BY FIELD(status, 'pending','in_progress','completed'), 
                           FIELD(priority, 'high','medium','low'), 
                           due_date IS NULL, due_date ASC, id DESC";
        $tasks = $wpdb->get_results( "SELECT * FROM " . RTODO_TABLE . " $where $order" );

        settings_errors( 'rtodo' );
        ?>
        <div class="wrap rtodo-wrap">
            <h1><?php esc_html_e('To‑Do List', 'rtodo'); ?></h1>

            <div class="rtodo-grid">
                <div class="rtodo-form-card">
                    <h2><?php esc_html_e('Add a Task', 'rtodo'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'rtodo_save_task' ); ?>
                        <input type="hidden" name="rtodo_action" value="create">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="rtodo_title"><?php esc_html_e('Title', 'rtodo'); ?></label></th>
                                <td><input name="title" id="rtodo_title" type="text" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rtodo_description"><?php esc_html_e('Description', 'rtodo'); ?></label></th>
                                <td>
                                    <?php
                                    wp_editor( '', 'rtodo_description', [
                                        'textarea_name' => 'description',
                                        'media_buttons' => false,
                                        'teeny'         => true,
                                        'textarea_rows' => 5,
                                    ] );
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rtodo_due"><?php esc_html_e('Due Date', 'rtodo'); ?></label></th>
                                <td><input name="due_date" id="rtodo_due" type="date"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rtodo_priority"><?php esc_html_e('Priority', 'rtodo'); ?></label></th>
                                <td>
                                    <select name="priority" id="rtodo_priority">
                                        <?php foreach ( rtodo_priorities() as $k => $label ): ?>
                                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="rtodo_status"><?php esc_html_e('Status', 'rtodo'); ?></label></th>
                                <td>
                                    <select name="status" id="rtodo_status">
                                        <?php foreach ( rtodo_statuses() as $k => $label ): ?>
                                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( __( 'Save Task', 'rtodo' ) ); ?>
                    </form>
                </div>

                <div class="rtodo-list-card">
                    <h2><?php esc_html_e('Your Tasks', 'rtodo'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Title', 'rtodo'); ?></th>
                                <th><?php esc_html_e('Priority', 'rtodo'); ?></th>
                                <th><?php esc_html_e('Due', 'rtodo'); ?></th>
                                <th><?php esc_html_e('Status', 'rtodo'); ?></th>
                                <th><?php esc_html_e('Actions', 'rtodo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty($tasks) ): ?>
                            <tr><td colspan="5"><?php esc_html_e('No tasks yet. Add your first task!', 'rtodo'); ?></td></tr>
                        <?php else: foreach ( $tasks as $t ): ?>
                            <tr class="rtodo-row rtodo-status-<?php echo esc_attr($t->status); ?> rtodo-priority-<?php echo esc_attr($t->priority); ?>">
                                <td>
                                    <strong><?php echo esc_html( $t->title ); ?></strong>
                                    <?php if ( ! empty( $t->description ) ): ?>
                                        <div class="description"><?php echo wp_kses_post( wpautop( $t->description ) ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge"><?php echo esc_html( rtodo_priorities()[ $t->priority ] ?? $t->priority ); ?></span></td>
                                <td><?php echo $t->due_date ? esc_html( date_i18n( get_option('date_format'), strtotime( $t->due_date ) ) ) : '—'; ?></td>
                                <td><?php echo esc_html( rtodo_statuses()[ $t->status ] ?? $t->status ); ?></td>
                                <td class="rtodo-actions">
                                    <?php if ( $t->status !== 'completed' ): ?>
                                        <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'rtodo_complete' => $t->id ] ), 'rtodo_complete_' . $t->id ) ); ?>">
                                            <?php esc_html_e('Mark Complete','rtodo'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <button class="button button-small rtodo-edit" data-task='<?php echo json_encode( [
                                        'id' => (int) $t->id,
                                        'title' => $t->title,
                                        'description' => $t->description,
                                        'due_date' => $t->due_date,
                                        'priority' => $t->priority,
                                        'status' => $t->status,
                                    ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP ); ?>'><?php esc_html_e('Edit','rtodo'); ?></button>
                                    <a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'rtodo_delete' => $t->id ] ), 'rtodo_delete_' . $t->id ) ); ?>">
                                        <?php esc_html_e('Delete','rtodo'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <form id="rtodo-edit-modal" method="post" class="rtodo-hidden">
                <?php wp_nonce_field( 'rtodo_save_task' ); ?>
                <input type="hidden" name="rtodo_action" value="update">
                <input type="hidden" name="task_id" value="0">
                <h2><?php esc_html_e('Edit Task', 'rtodo'); ?></h2>
                <p>
                    <label><?php esc_html_e('Title','rtodo'); ?><br>
                        <input name="title" type="text" class="regular-text" required>
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e('Description','rtodo'); ?></label>
                </p>
                <?php
                wp_editor( '', 'rtodo_description_edit', [
                    'textarea_name' => 'description',
                    'media_buttons' => false,
                    'teeny'         => true,
                    'textarea_rows' => 10,
                ] );
                ?>
                <p>
                    <label><?php esc_html_e('Due Date','rtodo'); ?><br>
                        <input name="due_date" type="date">
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e('Priority','rtodo'); ?><br>
                        <select name="priority">
                            <?php foreach ( rtodo_priorities() as $k => $label ): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e('Status','rtodo'); ?><br>
                        <select name="status">
                            <?php foreach ( rtodo_statuses() as $k => $label ): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p class="submit">
                    <?php submit_button( __( 'Update Task', 'rtodo' ), 'primary', 'submit', false ); ?>
                    <button type="button" class="button rtodo-cancel"><?php esc_html_e('Cancel', 'rtodo'); ?></button>
                </p>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.rtodo-edit').on('click', function(e) {
                e.preventDefault();
                const data = JSON.parse(this.getAttribute('data-task'));
                const modal = $('#rtodo-edit-modal');
                
                // Populate form fields
                modal.find('input[name="task_id"]').val(data.id);
                modal.find('input[name="title"]').val(data.title);
                modal.find('input[name="due_date"]').val(data.due_date || '');
                modal.find('select[name="priority"]').val(data.priority);
                modal.find('select[name="status"]').val(data.status);
                
                // Handle description editor
                if (window.tinymce && tinymce.get('rtodo_description_edit')) {
                    tinymce.get('rtodo_description_edit').setContent(data.description || '');
                } else {
                    // Fallback for textarea
                    const textarea = modal.find('textarea[name="description"]');
                    if (textarea.length) {
                        textarea.val(data.description || '');
                    }
                }
                
                modal.removeClass('rtodo-hidden').show();
                $('html, body').animate({
                    scrollTop: modal.offset().top - 100
                }, 500);
            });
            
            // Add a cancel/close button functionality
            $('.rtodo-cancel').on('click', function() {
                $('#rtodo-edit-modal').addClass('rtodo-hidden').hide();
            });
            
            $(document).on('keyup', function(e) {
                if (e.key === 'Escape') {
                    $('#rtodo-edit-modal').addClass('rtodo-hidden').hide();
                }
            });
        });
        </script>
        <?php
    }

    // Cron: email reminders 1 day before due date, only for non-completed tasks
    add_action( 'rtodo_daily_reminder', function() {
        global $wpdb;
        $tomorrow = new DateTime( 'tomorrow', wp_timezone() );
        $date = $tomorrow->format('Y-m-d');
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, u.user_email
             FROM " . RTODO_TABLE . " t
             JOIN {$wpdb->users} u ON u.ID = t.user_id
             WHERE t.due_date = %s AND t.status != 'completed'",
            $date
        ) );
        if ( empty( $rows ) ) return;

        // Group tasks by user
        $by_user = [];
        foreach ( $rows as $r ) {
            $by_user[ $r->user_id ][] = $r;
        }
        foreach ( $by_user as $uid => $tasks ) {
            $email = $tasks[0]->user_email;
            $subject = __( 'Reminder: Tasks due tomorrow', 'rtodo' );
            $lines = [];
            foreach ( $tasks as $t ) {
                $lines[] = sprintf( "- %s (%s)", $t->title, ucfirst($t->priority) );
            }
            $message = __( "Hi,

You have tasks due tomorrow:

", 'rtodo' ) . implode( "
", $lines ) . "

" . home_url();
            wp_mail( $email, $subject, $message );
        }
    } );

    // Optional: Shortcode for front‑end list (read‑only)
    add_shortcode( 'rtodo_list', function( $atts ){
        if ( ! is_user_logged_in() ) return '';
        $user_id = get_current_user_id();
        global $wpdb;
        $tasks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . RTODO_TABLE . " WHERE user_id=%d ORDER BY status, priority, due_date", $user_id
        ) );
        ob_start();
        echo '<div class="rtodo-frontend"><ul>';
        foreach ( $tasks as $t ) {
            $due = $t->due_date ? date_i18n( get_option('date_format'), strtotime($t->due_date) ) : '—';
            printf(
                '<li><strong>%s</strong> — %s — %s</li>',
                esc_html($t->title),
                esc_html( ucfirst(str_replace('_',' ',$t->status)) ),
                esc_html($due)
            );
        }
        echo '</ul></div>';
        return ob_get_clean();
    } );
