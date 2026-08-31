<?php
declare(strict_types=1);

namespace Heyrecruit\Http;

/**
 * HTTP-Grenze des SDK. Die Implementierung ist austauschbar, damit alles darueber testbar bleibt.
 */
interface Transport {

	/**
	 * @param string $path    The endpoint path, relative to the API base URL.
	 * @param array  $query   Query parameters.
	 * @param array  $headers Complete header lines.
	 *
	 * @return ApiResponse
	 */
	public function get(string $path, array $query = [], array $headers = []): ApiResponse;

	/**
	 * @param string $path    The endpoint path, relative to the API base URL.
	 * @param array  $data    Payload, sent as a JSON body.
	 * @param array  $headers Complete header lines.
	 *
	 * @return ApiResponse
	 */
	public function post(string $path, array $data = [], array $headers = []): ApiResponse;

	/**
	 * Sendet ein klassisches Formular-POST - der Auth-Endpunkt erwartet keine JSON-Payload.
	 *
	 * @param string $path   The endpoint path, relative to the API base URL.
	 * @param array  $fields The form fields.
	 *
	 * @return ApiResponse
	 */
	public function postForm(string $path, array $fields): ApiResponse;
}
