<?php

namespace Drupal\store_locator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\address\AddressInterface;

class StoreLocatorController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a StoreLocatorController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Renders the map page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return array
   *   A render array for the map page.
   */
  public function map(Request $request) {
    $build = [
      '#markup' => '<div id="map" style="width: 100%; height: 500px;"></div>',
      '#attached' => [
        'library' => [
          'leaflet/leaflet',
          'store_locator/store_locator_map',
        ],
      ],
    ];
    
    return $build;
  }

  /**
   * Calculates the Haversine distance between two points.
   *
   * @param float $latitudeFrom
   *   Latitude of the start point.
   * @param float $longitudeFrom
   *   Longitude of the start point.
   * @param float $latitudeTo
   *   Latitude of the end point.
   * @param float $longitudeTo
   *   Longitude of the end point.
   * @param float $earthRadius
   *   Mean radius of Earth in kilometers.
   *
   * @return float
   *   Distance between points in kilometers.
   */
  private function haversineGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371) {
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $longitudeTo - $longitudeFrom;

    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos($latFrom) * cos($latTo) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
  }

  /**
   * Fetches the nearest stores based on provided coordinates.
   *
   * @param float $lat
   *   Latitude of the user's location.
   * @param float $lng
   *   Longitude of the user's location.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the nearest stores.
   */
  public function getStores($lat, $lng) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'store');
    $query->accessCheck(FALSE);
    $nids = $query->execute();

    $stores = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $store_distances = [];

    foreach ($stores as $store) {
      $store_lat = $store->get('field_geofield')->first()->getValue()['lat'];
      $store_lng = $store->get('field_geofield')->first()->getValue()['lon'];
      $distance = $this->haversineGreatCircleDistance($lat, $lng, $store_lat, $store_lng);
      $store_distances[$store->id()] = $distance;
    }

    asort($store_distances);
    $nearest_stores_ids = array_slice(array_keys($store_distances), 0, 5, true);

    $nearest_stores = [];

    foreach ($nearest_stores_ids as $store_id) {
      $store = $stores[$store_id];
      $nearest_stores[] = [
        'id' => $store->id(),
        'title' => $store->getTitle(),
        'address' => $this->getStoreAddress($store),
        'lat' => $store->get('field_geofield')->first()->getValue()['lat'],
        'lon' => $store->get('field_geofield')->first()->getValue()['lon'],
        'distance' => $store_distances[$store_id],
      ];
    }

    usort($nearest_stores, function($a, $b) {
      return $b['distance'] <=> $a['distance'];
    });

    return new JsonResponse($nearest_stores);
  }

  /**
   * Gets the formatted address of a store.
   *
   * @param \Drupal\node\Entity\Node $store
   *   The store node entity.
   *
   * @return string|null
   *   The formatted address or NULL if not found.
   */
  private function getStoreAddress(Node $store) {
    if ($store && $store->hasField('field_address')) {
      $address_field = $store->get('field_address')->first();
      if ($address_field instanceof AddressInterface) {
        $address = sprintf(
          "%s %s, %s, %s, %s, %s",
          $address_field->getAddressLine1(),
          $address_field->getAddressLine2(),
          $address_field->getLocality(),
          $address_field->getAdministrativeArea(),
          $address_field->getPostalCode(),
          $address_field->getCountryCode()
        );
        return preg_replace('/\s+/', ' ', trim($address, ", "));
      }
    }
    return NULL;
  }
}
