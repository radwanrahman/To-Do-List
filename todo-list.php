<?php
/**
 * Plugin Name: RToDo — To-Do List for WordPress
 * Description: A simple, per-user to-do list inside the WordPress Dashboard with priorities, statuses, and email reminders.
 * Version: 1.0.0
 * Author: Radwan Rahman (Project: 231-134-009)
 * Text Domain: rtodo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { 
    exit; 
}

define( 'RTODO_VERSION', '1.0.0' );
define( 'RTODO_PLUGIN_FILE', __FILE__ );
define( 'RTODO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTODO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

global $wpdb;
define( 'RTODO_TABLE', $wpdb->prefix . 'rtodo_tasks' );


// Activation
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

// Deactivation 
register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'rtodo_daily_reminder' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'rtodo_daily_reminder' );
    }
} );


// Admin style
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'toplevel_page_rtodo' ) return;
    wp_enqueue_style( 'rtodo-admin', RTODO_PLUGIN_URL . 'assets/css/admin.css', [], RTODO_VERSION );
} );


// Admin menu
add_action( 'admin_menu', function() {
    add_menu_page(
        'To-Do List',
        'RToDo',
        'read',
        'rtodo',
        'rtodo_render_page',
        'dashicons-list-view',
        26
    );
});

// helper -priorities
function rtodo_priorities() {
    return [
        'low'    => 'Low',
        'medium' => 'Medium',
        'high'   => 'High',
    ];
}

// helper -statuses
function rtodo_statuses() {
    return [
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
    ];
}

add_action( 'admin_init', 'rtodo_handle_request' );

function rtodo_handle_request() {
    if ( ! isset($_POST['rtodo_action']) ) return;
    if ( ! wp_verify_nonce($_POST['rtodo_nonce'], 'rtodo_action') ) return;

    global $wpdb;
    $user_id = get_current_user_id();
    $action  = sanitize_text_field($_POST['rtodo_action']);

    if ( $action === 'create' ) {
        $wpdb->insert( RTODO_TABLE, [
            'user_id'     => $user_id,
            'title'       => sanitize_text_field($_POST['title']),
            'description' => sanitize_textarea_field($_POST['description']),
            'due_date'    => sanitize_text_field($_POST['due_date']),
            'priority'    => sanitize_text_field($_POST['priority']),
        ]);
    }

    
    if ( $action === 'delete' && isset($_POST['id']) ) {
        $wpdb->delete( RTODO_TABLE, ['id' => intval($_POST['id']), 'user_id' => $user_id] );
    }

    if ( $action === 'complete' && isset($_POST['id']) ) {
        $wpdb->update( RTODO_TABLE, ['status' => 'completed'], ['id' => intval($_POST['id']), 'user_id' => $user_id] );
    }
}

function rtodo_render_page() {
    global $wpdb;
    $user_id = get_current_user_id();
    $tasks = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM " . RTODO_TABLE . " WHERE user_id = %d ORDER BY due_date ASC",
            $user_id
        )
    );
    ?>
    <div class="wrap rtodo-wrap">
        <div class="rtodo-form-card">
            <h2>Add Task</h2>
            <form method="post">
                <?php wp_nonce_field('rtodo_action','rtodo_nonce'); ?>
                <input type="hidden" name="rtodo_action" value="create">
                <p><input type="text" name="title" placeholder="Task Title" required></p>
                <p><textarea name="description" placeholder="Description"></textarea></p>
                <p><input type="date" name="due_date"></p>
                <p>
                    <select name="priority">
                        <?php foreach ( rtodo_priorities() as $k => $v ) : ?>
                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p><button type="submit" class="button button-primary">Add Task</button></p>
            </form>
        </div>
    </div>
    <?php
}


function rtodo_render_page() {
    global $wpdb;
    $user_id = get_current_user_id();
    $tasks = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM " . RTODO_TABLE . " WHERE user_id = %d ORDER BY due_date ASC",
            $user_id
        )
    );
    ?>
    <div class="wrap rtodo-wrap">
        <div class="rtodo-form-card">
            <h2>Add Task</h2>
            <form method="post">
                <?php wp_nonce_field('rtodo_action','rtodo_nonce'); ?>
                <input type="hidden" name="rtodo_action" value="create">
                <p><input type="text" name="title" placeholder="Task Title" required></p>
                <p><textarea name="description" placeholder="Description"></textarea></p>
                <p><input type="date" name="due_date"></p>
                <p>
                    <select name="priority">
                        <?php foreach ( rtodo_priorities() as $k => $v ) : ?>
                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p><button type="submit" class="button button-primary">Add Task</button></p>
            </form>
        </div>

        <div class="rtodo-list-card">
            <h2>Your Tasks</h2>
            <table class="widefat">
                <thead><tr><th>Title</th><th>Due</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ( $tasks as $task ) : ?>
                    <tr class="rtodo-row rtodo-status-<?php echo esc_attr($task->status); ?>">
                        <td><?php echo esc_html($task->title); ?></td>
                        <td><?php echo esc_html($task->due_date); ?></td>
                        <td><?php echo esc_html($task->priority); ?></td>
                        <td><?php echo esc_html($task->status); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('rtodo_action','rtodo_nonce'); ?>
                                <input type="hidden" name="rtodo_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($task->id); ?>">
                                <button type="submit" class="button">Delete</button>
                            </form>
                            <?php if ( $task->status !== 'completed' ) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('rtodo_action','rtodo_nonce'); ?>
                                <input type="hidden" name="rtodo_action" value="complete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($task->id); ?>">
                                <button type="submit" class="button">Complete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// Frontend 
add_shortcode( 'rtodo_list', function() {
    global $wpdb;
    $user_id = get_current_user_id();
    if ( ! $user_id ) return '<p>Please login to view your tasks.</p>';

    $tasks = $wpdb->get_results( $wpdb->prepare("SELECT * FROM " . RTODO_TABLE . " WHERE user_id=%d ORDER BY due_date ASC", $user_id) );

    ob_start();
    echo '<ul class="rtodo-shortcode">';
    foreach ( $tasks as $task ) {
        echo '<li>' . esc_html($task->title) . ' - ' . esc_html($task->status) . '</li>';
    }
    echo '</ul>';
    return ob_get_clean();
});



