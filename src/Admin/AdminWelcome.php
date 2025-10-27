<?php

namespace Onionify\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminWelcome
{
    private const OPTION_KEY = 'onionify_welcome_pending';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerWelcomePage']);
        add_action('network_admin_menu', [$this, 'registerWelcomePage']);
        add_action('admin_init', [$this, 'maybeRedirectToWelcome']);
    }

    public static function activate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            update_site_option(self::OPTION_KEY, 1);
        } else {
            update_option(self::OPTION_KEY, 1);
        }
    }

    public function maybeRedirectToWelcome(): void
    {
        if (!is_admin() || !$this->currentUserCanSeeWelcome()) {
            return;
        }

        if (wp_doing_ajax() || wp_doing_cron() || isset($_GET['activate-multi'])) {
            return;
        }

        $pending = is_multisite()
            ? (int) get_site_option(self::OPTION_KEY, 0)
            : (int) get_option(self::OPTION_KEY, 0);

        if ($pending !== 1) {
            return;
        }

        // clear flag
        if (is_multisite()) {
            delete_site_option(self::OPTION_KEY);
        } else {
            delete_option(self::OPTION_KEY);
        }

        $base = is_network_admin() ? network_admin_url('admin.php') : admin_url('admin.php');
        wp_safe_redirect(add_query_arg(['page' => 'onionify-welcome'], $base));
        exit;
    }

    public function registerWelcomePage(): void
	{
		$parent_slug = is_network_admin() ? 'settings.php' : 'options-general.php';

		add_submenu_page(
			$parent_slug,
			'Onionify - Welcome',
			'Onionify - Welcome',
			$this->welcomeCapability(),
			'onionify-welcome',
			[$this, 'renderWelcomePage']
		);

		add_action('admin_head', function () use ($parent_slug) {
			remove_submenu_page($parent_slug, 'onionify-welcome');
		});
	}

    public function renderWelcomePage(): void
    {
        // koristi ispravnu konstantu iz main fajla
        $readme_url = plugins_url('readme.html', \ONIONIFY_PLUGIN_FILE);

        if (!$this->currentUserCanSeeWelcome()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'onionify'));
        }

        $exists = file_exists(dirname(\ONIONIFY_PLUGIN_FILE) . '/readme.html');
        ?>
        <div class="wrap">
            <?php if ($exists) : ?>
                <div style="border:0;overflow:hidden;background:#fff;">
					<iframe
						id="onionify-readme"
						src="<?php echo esc_url($readme_url); ?>"
						style="width:100%;border:0;display:block;"
						loading="lazy"
						referrerpolicy="no-referrer"
					></iframe>
				</div>
<?php add_action('admin_footer', function(){ ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const iframe = document.getElementById('onionify-readme');
  if (!iframe) return;

  // Adjust height after load
  iframe.addEventListener('load', function () {
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;

      // Set height to match scrollHeight of the HTML content
      iframe.style.height = doc.documentElement.scrollHeight + 'px';

      // Optional: watch for content resize (if styles/images load later)
      const observer = new ResizeObserver(() => {
        iframe.style.height = doc.documentElement.scrollHeight + 'px';
      });
      observer.observe(doc.documentElement);
    } catch (e) {
      console.warn('Onionify iframe resize skipped (cross-origin or access error).');
    }
  });
});
</script>
<?php }); ?>				
            <?php else : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('readme.html not found in the plugin root. Please ensure the file exists.', 'onionify'); ?></p>
                </div>
            <?php endif; ?>

            <p style="margin-bottom:64px; text-align: center;">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('options-general.php?page=onionify-settings')); ?>" style="padding: 8px 12px; font-weight: 600;">
                    <?php echo esc_html__('Go to Onionify settings', 'onionify'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function welcomeCapability(): string
    {
        // u network adminu koristi pravu capability
        return is_network_admin() ? 'manage_network_plugins' : 'activate_plugins';
    }

    private function currentUserCanSeeWelcome(): bool
    {
        return is_network_admin()
            ? current_user_can('manage_network_plugins')
            : current_user_can('activate_plugins');
    }
}
