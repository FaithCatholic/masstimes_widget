<?php

namespace Drupal\masstimes_widget;

use GuzzleHttp\ClientInterface;
use Drupal\Component\Serialization\Json;

/**
 * Queries the MassTimes API for parishes near a set of coordinates.
 */
class MassTimesService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $http;

  /**
   * Constructs a MassTimesService object.
   *
   * @param \GuzzleHttp\ClientInterface $http
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http) {
    $this->http = $http;
  }

  /**
   * Fetch nearest parishes from the API.
   *
   * @param float $lat
   *   The latitude to search around.
   * @param float $lon
   *   The longitude to search around.
   *
   * @return array
   *   The decoded API response.
   */
  public function fetchParishes(float $lat, float $lon): array {
    $url = "https://apiv4.updateparishdata.org/Churchs/?lat={$lat}&long={$lon}&pg=1";
    $resp = $this->http->request('GET', $url);
    return Json::decode($resp->getBody()->getContents());
  }

}
