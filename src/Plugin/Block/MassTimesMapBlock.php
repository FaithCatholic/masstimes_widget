<?php

namespace Drupal\masstimes_widget\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\masstimes_widget\MassTimesService;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'MassTimes Map Fullscreen' block.
 *
 * @phpstan-consistent-constructor
 */
#[Block(
  id: 'masstimes_map_block',
  admin_label: new TranslatableMarkup('MassTimes Map Fullscreen Block'),
  category: new TranslatableMarkup('MassTimes Widget'),
)]
class MassTimesMapBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a MassTimesMapBlock object.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\masstimes_widget\MassTimesService $service
   *   The MassTimes service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MassTimesService $service,
    protected RequestStack $requestStack,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(MassTimesService::class),
      $container->get('request_stack'),
      $container->get('logger.factory')
    );
  }

  /**
   * Provide default values for our lat and long.
   *
   * @return array<string, mixed>
   *   The default block configuration.
   */
  public function defaultConfiguration() {
    return [
      'default_lat' => '',
      'default_lon' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * Adds fields for default lat and long.
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The block configuration form with our fields added.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['default_lat'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default latitude'),
      '#default_value' => $config['default_lat'],
      '#description' => $this->t('If no URL query or geolocation is available, use this latitude.'),
    ];
    $form['default_lon'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default longitude'),
      '#default_value' => $config['default_lon'],
      '#description' => $this->t('If no URL query or geolocation is available, use this longitude.'),
    ];

    return $form;
  }

  /**
   * Saving default lat/long to block.
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $vals = $form_state->getValues();
    $this->configuration['default_lat'] = $vals['default_lat'];
    $this->configuration['default_lon'] = $vals['default_lon'];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array for the map and parish sidebar.
   */
  public function build() {
    $request = $this->requestStack->getCurrentRequest();
    $lat = $request?->query->get('lat');
    $lon = $request?->query->get('long');

    // If url doesn't have lat/long, we fall back to block defaults.
    if ((string) $lat === '' || (string) $lon === '') {
      $lat = $this->configuration['default_lat'];
      $lon = $this->configuration['default_lon'];
    }

    $parishes = [];
    if (is_numeric($lat) && is_numeric($lon)) {
      try {
        $parishes = $this->service->fetchParishes((float) $lat, (float) $lon);
        usort($parishes, fn($a, $b) => ($a['distance'] ?? 0) <=> ($b['distance'] ?? 0));
      }
      catch (RequestException $e) {
        $this->loggerFactory->get('masstimes_widget')->error($e->getMessage());
      }
    }

    // Build GeoJSON features.
    $features = [];
    foreach ($parishes as $i => $p) {
      if (!is_numeric($p['latitude'] ?? NULL) || !is_numeric($p['longitude'] ?? NULL)) {
        continue;
      }
      $features[] = [
        'type' => 'Feature',
        'geometry' => [
          'type' => 'Point',
          'coordinates' => [(float) $p['longitude'], (float) $p['latitude']],
        ],
        'properties' => [
          'index'   => $i,
          'name'    => $p['name'] ?? '',
          'address' => $p['church_address_street_address'] ?? '',
          'wptimes' => $p['church_worship_times'] ?? [],
          'distance' => is_numeric($p['distance'] ?? NULL) ? round((float) $p['distance'], 1) : NULL,
        ],
      ];
    }

    // Find our map center.
    if (!empty($features)) {
      [$lon0, $lat0] = $features[0]['geometry']['coordinates'];
      $center = [$lat0, $lon0];
    }
    elseif (is_numeric($lat) && is_numeric($lon)) {
      $center = [(float) $lat, (float) $lon];
    }
    else {
      $center = [0, 0];
    }

    // Build the settings array we will pass into twig and the javascript.
    $settings = [
      'mapOptions'  => ['center' => $center, 'zoom' => 12],
      'geojson'     => ['type' => 'FeatureCollection', 'features' => $features],
      'parishes'    => $parishes,
      // Default lat and long from block settings.
      'defaultLat'  => is_numeric($this->configuration['default_lat']) ? (float) $this->configuration['default_lat'] : NULL,
      'defaultLon'  => is_numeric($this->configuration['default_lon']) ? (float) $this->configuration['default_lon'] : NULL,
    ];

    return [
      '#theme'    => 'masstimes_map',
      '#settings' => $settings,
      '#attached' => [
        'library'        => ['masstimes_widget/masstimes_map'],
        'drupalSettings' => ['masstimes_widget' => $settings],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:lat', 'url.query_args:long'],
      ],
    ];
  }

}
