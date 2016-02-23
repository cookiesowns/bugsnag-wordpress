<?php
/*
Plugin Name: Bugsnag Error Monitoring
Plugin URI: https://bugsnag.com
Description: Bugsnag monitors for errors and crashes on your wordpress site, sends them to your bugsnag.com dashboard, and notifies you by email of each error.
Version: 1.2.1
Author: Bugsnag Inc.
Author URI: https://bugsnag.com
License: GPLv2 or later
*/

class Bugsnag_Wordpress
{
    private static $COMPOSER_AUTOLOADER = 'vendor/autoload.php';
    private static $PACKAGED_AUTOLOADER = 'bugsnag-php/Autoload.php';
    private static $DEFAULT_NOTIFY_SEVERITIES = 'fatal,error';

    private static $NOTIFIER = array(
        'name' => 'Bugsnag Wordpress (Official)',
        'version' => '1.2.1',
        'url' => 'https://bugsnag.com/notifiers/wordpress'
    );

    private $client;
    private $apiKey;
    private $notifySeverities;
    private $filterFields;
    private $pluginBase;

    public function __construct()
    {
        // Activate bugsnag error monitoring as soon as possible
        $this->activateBugsnag();

        $this->pluginBase = 'bugsnag/bugsnag.php';

        // Run init actions (loading wp user)
        add_action('init', array($this, 'initActions'));

        // Load admin actions (admin links and pages)
        add_action('admin_menu', array($this, 'adminMenuActions'));

        // Load network admin menu if using multisite
        add_action('network_admin_menu', array($this, 'networkAdminMenuActions'));

        add_action('wp_ajax_test_bugsnag', array($this, 'testBugsnag'));
    }

    private function activateBugsnag()
    {
        // Require bugsnag-php
        if(file_exists($this->relativePath(self::$COMPOSER_AUTOLOADER))) {
            require_once $this->relativePath(self::$COMPOSER_AUTOLOADER);
        } elseif (file_exists($this->relativePath(self::$PACKAGED_AUTOLOADER))) {
            require_once $this->relativePath(self::$PACKAGED_AUTOLOADER);
        } elseif (!class_exists('Bugsnag_Client')){
            error_log("Bugsnag Error: Couldn't activate Bugsnag Error Monitoring due to missing Bugsnag library!");
            return;
        }

        //Load bugsnag settings
        if( get_site_option('bugsnag_network') ) {
          // Multisite
          $this->apiKey           = get_site_option( 'bugsnag_api_key' );
          $this->notifySeverities = get_site_option( 'bugsnag_notify_severities' );
          $this->filterFields     = get_site_option( 'bugsnag_filterfields' );

        } else if( defined( 'BUGSNAG_API_KEY' ) && WP_ENV != 'production' ) {
          // Allow different API_KEY than one set in dashboard for dev ENV
          $this->apiKey           = BUGSNAG_API_KEY;
          $this->notifySeverities = defined( 'BUGSNAG_NOTIFY_SEVERITIES' ) ? BUGSNAG_NOTIFY_SEVERITIES : get_option( 'bugsnag_notify_severities' );
          $this->filterFields     = defined( 'BUGSNAG_FILTERFIELDS' ) ? BUGSNAG_FILTERFIELDS : get_option( 'bugsnag_filterfields' );

        } else {
          // Load regular bugsnag settings
          $this->apiKey           = get_option( 'bugsnag_api_key' );
          $this->notifySeverities = get_option( 'bugsnag_notify_severities' );
          $this->filterFields     = get_option( 'bugsnag_filterfields' );

        }

        $this->constructBugsnag();
    }

    private function constructBugsnag() {
        // Activate the bugsnag client
        if(!empty($this->apiKey)) {
            $this->client = new Bugsnag_Client($this->apiKey);

            $this->client->setReleaseStage($this->releaseStage())
                         ->setErrorReportingLevel($this->errorReportingLevel())
                         ->setFilters($this->filterFields());

            $this->client->setNotifier(self::$NOTIFIER);

            // Hook up automatic error handling
            set_error_handler(array($this->client, "errorHandler"));
            set_exception_handler(array($this->client, "exceptionHandler"));
        }

    }

    private function relativePath($path)
    {
        return dirname(__FILE__) . '/' . $path;
    }

    private function errorReportingLevel()
    {
        $notifySeverities = empty($this->notifySeverities) ? self::$DEFAULT_NOTIFY_SEVERITIES : $this->notifySeverities;
        $level = 0;

        $severities = explode(",", $notifySeverities);
        foreach($severities as $severity) {
            $level |= Bugsnag_ErrorTypes::getLevelsForSeverity($severity);
        }

        return $level;
    }

    private function filterFields()
    {
        return array_map('trim', explode("\n", $this->filterFields));
    }

    private function releaseStage()
    {
        return defined('WP_ENV') ? WP_ENV : "production";
    }


    // Action hooks
    public function initActions()
    {
        // Set the bugsnag user using the current WordPress user if available
        $wpUser = wp_get_current_user();
        if(!empty($this->client) && !empty($wpUser)) {
            $user = array();

            if(!empty($wpUser->user_login)) {
                $user['id'] = $wpUser->user_login;
            }

            if(!empty($wpUser->user_email)) {
                $user['email'] = $wpUser->user_email;
            }

            if(!empty($wpUser->user_display_name)) {
                $user['name'] = $wpUser->user_display_name;
            }

            $this->client->setUser($user);
        }
    }

    public function adminMenuActions()
    {
        if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network($this->pluginBase)) {
            // Add the "settings" link to the Bugsnag row of plugins.php
            add_filter('plugin_action_links', array($this, 'pluginActionLinksFilter'), 10, 2);

            // Create the settings page
            add_options_page('Bugsnag Settings', 'Bugsnag', 'manage_options', 'bugsnag', array($this, 'renderSettings'));
        }
    }

    public function networkAdminMenuActions()
    {
        if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($this->pluginBase)) {
            // Create the network settings page
            add_submenu_page('settings.php', 'Bugsnag Settings', 'Bugsnag', 'manage_network_options', 'bugsnag', array($this, 'renderSettings'));
        }
    }

    private function updateNetworkSettings( $settings )
    {
        // Update options
        update_site_option('bugsnag_api_key', isset($_POST['bugsnag_api_key']) ? $_POST['bugsnag_api_key'] : '');
        update_site_option('bugsnag_notify_severities', isset($_POST['bugsnag_notify_severities']) ? $_POST['bugsnag_notify_severities'] : '');
        update_site_option('bugsnag_filterfields', isset($_POST['bugsnag_filterfields']) ? $_POST['bugsnag_filterfields'] : '');
        update_site_option('bugsnag_network', true);

        // Update variables
        $this->apiKey           = get_site_option( 'bugsnag_api_key' );
        $this->notifySeverities = get_site_option( 'bugsnag_notify_severities' );
        $this->filterFields     = get_site_option( 'bugsnag_filterfields' );

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }


    // Filter hooks
    public function pluginActionLinksFilter($links, $file)
    {
        // Add the "settings" link to the Bugsnag plugin row
        if(basename($file) == basename(__FILE__)) {
            $settings_link = '<a href="options-general.php?page=bugsnag">Settings</a>';
            array_push($links, $settings_link);
        }

        return $links;
    }

    public function testBugsnag()
    {
        $this->apiKey = $_POST["bugsnag_api_key"];
        $this->notifySeverities = $_POST['bugsnag_notify_severities'];
        $this->filterFields = $_POST['bugsnag_filterfields'];

        $this->constructBugsnag();
        $this->client->notifyError('BugsnagTest', 'Testing bugsnag',
            array('notifier' => self::$NOTIFIER));

        die();
    }


    // Renderers
    public function renderSettings()
    {
        if ( ! empty($_POST[ 'action' ]) && $_POST[ 'action' ] == 'update') {
            $this->updateNetworkSettings( $_POST );
        }

        include $this->relativePath('views/settings.php');
    }

    private function renderOption($name, $value, $current)
    {
        $selected = ($value == $current) ? " selected=\"selected\"" : "";
        echo "<option value=\"$value\"$selected>$name</option>";
    }

    /**
     * Fluent interface to $this->client, simply call the methods on this object and this will proxy them through.
     *
     * @param $method
     * @param $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if (method_exists($this->client, $method)) {
            return call_user_func_array(array($this->client, $method), $arguments);
        }

        throw new BadMethodCallException(sprintf('Method %s does not exist on %s or Bugsnag_Client', $method, __CLASS__ ));
    }
}

$bugsnagWordpress = new Bugsnag_Wordpress();
