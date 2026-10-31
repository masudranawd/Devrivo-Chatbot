<?php
/**
 * REST API registration.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Admin\PreviewController;
use DevRivo\Chatbot\Admin\TransferController;
use DevRivo\Chatbot\AI\AiController;
use DevRivo\Chatbot\AI\ChatEngine;
use DevRivo\Chatbot\Core\Frontend\ChatController;
use DevRivo\Chatbot\Core\Frontend\ConfigController;
use DevRivo\Chatbot\Core\Frontend\FormsController;
use DevRivo\Chatbot\Core\Frontend\SearchController;
use DevRivo\Chatbot\Core\Frontend\SessionController;
use DevRivo\Chatbot\Core\Frontend\WidgetConfig;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Presets\PresetRepository;
use DevRivo\Chatbot\Core\Presets\PresetsController;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Settings\SettingsController;
use DevRivo\Chatbot\Data\Leads;
use DevRivo\Chatbot\Data\LeadsController;
use DevRivo\Chatbot\Integrations\Email;
use DevRivo\Chatbot\Integrations\IntegrationsController;
use DevRivo\Chatbot\Knowledge\Knowledge;
use DevRivo\Chatbot\Knowledge\KnowledgeController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST controller under devrivo-chatbot/v1.
 */
final class Rest {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'devrivo-chatbot/v1';

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings repository.
	 * @param PresetRepository $presets   Preset repository.
	 * @param Leads            $leads     Leads repository.
	 * @param Knowledge        $knowledge Knowledge index.
	 */
	public function __construct( private Settings $settings, private PresetRepository $presets, private Leads $leads, private Knowledge $knowledge ) {}

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers routes. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		( new SettingsController( $this->settings ) )->register_routes();
		( new ConfigController( new WidgetConfig( $this->settings ) ) )->register_routes();
		( new PresetsController( $this->settings, $this->presets, new PresetApplier( $this->settings, $this->presets ) ) )->register_routes();
		( new SessionController() )->register_routes();
		( new FormsController( $this->settings, $this->leads, new Email( $this->settings ) ) )->register_routes();
		( new LeadsController( $this->leads ) )->register_routes();
		( new SearchController( $this->knowledge ) )->register_routes();
		( new KnowledgeController( $this->knowledge ) )->register_routes();
		( new ChatController( new ChatEngine( $this->settings, $this->knowledge, Plugin::instance()->ai_client() ) ) )->register_routes();
		( new AiController( $this->settings ) )->register_routes();
		( new PreviewController( $this->settings ) )->register_routes();
		( new TransferController( $this->settings, new PresetApplier( $this->settings, $this->presets ) ) )->register_routes();
		( new IntegrationsController( new Email( $this->settings ) ) )->register_routes();
	}
}
