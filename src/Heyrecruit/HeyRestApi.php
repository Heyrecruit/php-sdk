<?php
	/**
	 * Class HeyRestApi
	 *
	 * A sample class to communicate with the heyrecruit rest api
	 *
	 * @author        Oleg Mutzenberger
	 * @email         oleg@artrevolver.de
	 * @web           https://scope-recruiting.de
	 * @copyright     Copyright 2024, Artrevolver GmbH
	 * @license       http://opensource.org/licenses/mit-license.php MI
	 *
	 */
	declare(strict_types=1);
	
	namespace Heyrecruit;
	
	use Exception;
	use InvalidArgumentException;
	
	/**
	 * Class HeyRestApi
	 *
	 */
	class HeyRestApi {
		
		/**
		 * API request url.
		 *
		 * @var string $scope_url
		 */
		public $scope_url;
		
		/**
		 * @var $auth array  Holds auth information.
		 *
		 */
		protected array $auth = [
			'token'      => null,
			'expiration' => null,
		];
		
		/**
		 * Auth configuration.
		 *
		 * array    ['client_id']       int The company id of a registered SCOPE client.
		 *          ['client_secret']   string The company secret.
		 *
		 * @var array $auth_config (See above)
		 *
		 */
		protected array $auth_config = [
			'client_id'     => null,
			'client_secret' => null
		];
		
		private const MAX_AUTH_RETRIES            = 3;
		private const CONNECT_TIMEOUT_SECONDS     = 5;
		private const REQUEST_TIMEOUT_SECONDS     = 15;
		private const TOKEN_EXPIRY_MARGIN_SECONDS = 60;
		
		/**
		 * Job filter data submitted with get jobs request.
		 *
		 * array['job_ids']                 array job ids
		 *      ['company_location_ids']    array company location ids
		 *      ['department']              array Job department (e.g. Software development)
		 *      ['employment']              array Job employment (e.g. full time, part time)
		 *      ['language']                string The language shortcut for strings to be returned from scope
		 *      ['address']                 string Job address
		 *      ['search']                  string search
		 *      ['area_search_distance']    int Area search distance for address. Default 60000 => 60 km
		 *      ['internal_title']          string Internal job title
		 *
		 *
		 * @var array $filter (See above)
		 *
		 */
		private array $filter = [
			'job_ids'              => [],
			'company_location_ids' => [],
			'departments'          => [],
			'employments'          => [],
			'internal_titles'      => [],
			'language'             => null,
			'search'               => null,
			'address'              => null,
			'area_search_distance' => 60000, // 60 km
			'limit'                => 999,
			'page'                 => 1,
		];
		
		/**
		 * SCOPE API request urls.
		 *
		 * array['auth']                string Authentication and requesting an authorization token url.
		 *      ['get_company']         string Get company data url.
		 *      ['get_jobs']            string Get jobs data url.
		 *      ['get_job']             string Get single job data url.
		 *      ['add_applicant']       string Add  new applicant url.
		 *      ['upload_documents']    string Upload applicant documents url.
		 *      ['delete_documents']    string Delete applicant documents url.
		 *
		 *
		 * @var array $url (See above)
		 */
		private array $url = [
			'auth'                      => 'auth',
			'get_company'               => 'companies/view',
			'get_company_by_sub_domain' => 'companies/view-by-domain',
			'get_jobs'                  => 'jobs/index',
			'get_job'                   => 'jobs/view',
			'get_appointment'           => 'appointments/by-token',
			'respond_appointment'       => 'appointments/respond',
			'apply'                     => 'applicant-jobs/apply',
			'upload_documents'          => 'rest-applicants/uploadDocument',
			'delete_documents'          => 'rest-applicants/deleteDocument',
		];
		
		/**
		 * Initializes a new instance of the SDK with the specified configuration settings.
		 *
		 * @param array $config An associative array of configuration settings,
		 *                      including the SCOPE_URL parameter and optional GA_TRACKING parameter.
		 *
		 * @throws InvalidArgumentException|Exception if the configuration settings are missing or incomplete.
		 */
		function __construct(array $config) {
			
			if(empty($config)) {
				throw new InvalidArgumentException('No configuration settings submitted.');
			}
			
			if(!isset($config['SCOPE_URL'])) {
				throw new InvalidArgumentException('Missing SCOPE_URL parameter.');
			}
			
			$this->scope_url = $config['SCOPE_URL'];
			
			if (substr($this->scope_url, -1) === "/") {
				$this->scope_url = rtrim($this->scope_url, "/");
			}
			
			$this->setAuthConfig($config);
			$this->authenticate();
		}
		
		/**
		 *  Sets auth data for requesting an JWT access token
		 *
		 * @param array $config  ['SCOPE_CLIENT_ID']         int The client id of a registered Heyrecruit client.
		 *                       ['SCOPE_CLIENT_SECRET']     string The client secret of a registered Heyrecruit client.
		 *
		 * @return void
		 */
		public function setAuthConfig(array $config): void {
			if(!isset($config['SCOPE_CLIENT_ID'])) {
				throw new InvalidArgumentException('Missing CLIENT_ID parameter.');
			}
			if(!isset($config['SCOPE_CLIENT_SECRET'])) {
				throw new InvalidArgumentException('Missing CLIENT_SECRET parameter.');
			}
			
			$this->auth_config = [
				'client_id'     => $config['SCOPE_CLIENT_ID'],
				'client_secret' => $config['SCOPE_CLIENT_SECRET']
			];
		}
		
		/**
		 *  Swaps a client_id and client_secret for an JWT auth token.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function authenticate(bool $force = false): array {
			if (!$force) {
				$cached = $this->readCachedAuth();
				
				if ($cached !== null) {
					$this->auth = $cached;
					return ['status' => 'success', 'data' => $cached];
				}
			}
			
			$curl = curl_init($this->endpoint($this->url['auth']));
			
			curl_setopt_array($curl, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTFIELDS     => $this->auth_config,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
				CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT_SECONDS,
			]);
			
			$result = $this->execute($curl);
			
			if (($result['response']['status'] ?? null) === 'success' && is_array($result['response']['data'] ?? null)) {
				$auth = $result['response']['data'];
				// Sicherheitsabstand einmal hier abziehen, damit Session-Cache und Instanz denselben Wert tragen.
				$auth['expiration'] = (int)($auth['expiration'] ?? 0) - self::TOKEN_EXPIRY_MARGIN_SECONDS;
				
				$this->auth = $auth;
				$this->writeCachedAuth($auth);
				
				return $result['response'];
			}
			
			throw new Exception('Auth error! Message from Heyrecruit: ' . $this->describeFailure($result));
		}
		
		/**
		 * Checks whether the current access token has expired and renews it if necessary.
		 *
		 * @return bool True if the access token is still valid or has been successfully renewed, false otherwise.
		 *
		 * @throws Exception if an error occurs while authenticating or renewing the access token.
		 */
		private function checkAndRenewToken(bool $force = false): bool {
			if ($force || (int)($this->auth['expiration'] ?? 0) < time()) {
				return ($this->authenticate($force)['status'] ?? null) === 'success';
			}
			
			return true;
		}
		
		/**
		 *  Sets the job filter data submitted with get jobs request
		 *
		 * @param $queryParams  String
		 *
		 * @return void
		 *
		 */
		public function setFilter(string $queryParams = ''): void {
			if(!empty($queryParams)) {
				$qs = preg_replace("/(?<=^|&)(\w+)(?==)/", "$1[]", $queryParams);
				parse_str($qs, $newGET);
				// Replace only the wanted keys
				$this->filter = array_replace($this->filter, array_intersect_key($newGET, $this->filter));
				
				// Only one language allowed
				$this->filter['language'] = is_array($this->filter['language']) ? $this->filter['language'][0] : $this->filter['language'];
				// Only one address allowed
				$this->filter['address'] = is_array($this->filter['address']) ? ($this->filter['address'][0] ?? null) : $this->filter['address'];
				
				if(!empty($this->filter['page']) && is_array($this->filter['page'])) {
					$this->filter['page'] = $this->filter['page'][0];
				}else{
					$this->filter['page'] = 1;
				}
			}
		}
		
		public function apply(array $data): array {
			$url =  $this->url['apply'];
			return $this->apiRequest($url, $data, 'POST');
		}
		
		/**
		 *  Get company detail.
		 *
		 * @param $companyId int|null
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getCompanyDetail(int $companyId): array {
			$url = $this->url['get_company'];
			return $this->apiRequest($url, ['company' => $companyId]);
		}
		
		/**
		 *  Get company detail by subdomain
		 *
		 * @param $subDomain string
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getCompanyDetailBySubDomain(string $subDomain): array {
			$url = $this->url['get_company_by_sub_domain'];
			
			return $this->apiRequest($url, ['domain' => $subDomain]);
		}
		
		/**
		 *  Find jobs based on the pre-defined filter values.
		 *
		 * @param $companyId int|null
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJobs(?int $companyId = null): array {
			$url =  $this->url['get_jobs'];
			
			$this->filter['company'] = $companyId;
			$this->filter['status'] = 1;
			
			return $this->apiRequest($url, $this->filter);
		}
		
		/**
		 *  Get one job.
		 *
		 * @param int|null $companyId
		 * @param int $jobId
		 * @param int $companyLocationId
		 *
		 * @return array
		 */
		public function getJob(?int $companyId, int $jobId, int $companyLocationId): array {
			
			$url =  $this->url['get_job'];
			
			return $this->apiRequest($url, [
				'company'          => $companyId,
				'job_id'              => $jobId,
				'company_location_id' => $companyLocationId,
			]);
		}

		/**
		 *  Get the RSVP appointment landing data for a token.
		 *
		 * @param string $token
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getAppointmentByToken(string $token): array {
			$url = $this->url['get_appointment'];

			return $this->apiRequest($url, ['token' => $token]);
		}

		/**
		 *  Send the applicant's RSVP answer for a token. POST, because it changes state — a link must not
		 *  trigger it (mail scanners, prefetch).
		 *
		 * @param string $token
		 * @param string $action 'confirm' or 'decline'
		 *
		 * @return array
		 * @throws Exception
		 */
		public function respondToAppointment(string $token, string $action): array {
			$url = $this->url['respond_appointment'];

			return $this->apiRequest($url, ['token' => $token, 'action' => $action], 'POST');
		}
		
		/**
		 * Generates Google Tag Manager code for the specified public ID.
		 *
		 * @param string|null $publicId The public ID of the Google Tag Manager container.
		 *
		 * @return array An associative array containing the Google Tag Manager code
		 *               for the head and body sections of a webpage.
		 */
		public function getGoogleTagCode(?string $publicId = ''): array {
			$tagCode = [
				'head' => '',
				'body' => ''
			];
			
			if(!empty($publicId)) {
				$tagCode['head'] =
					"<!-- Google Tag Manager -->" .
					"<script> (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start': " .
					"new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0], " .
					"j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src= " .
					"'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f); " .
					"})(window,document,'script','dataLayer', " .
					$this->jsString($publicId) . ");</script> " .
					"<!-- End Google Tag Manager -->";
				
				$tagCode['body'] =
					'<!-- Google Tag Manager (noscript) -->' .
					'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' .
					htmlspecialchars(rawurlencode($publicId), ENT_QUOTES, 'UTF-8') . '" ' .
					'height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript> ' .
					'<!-- End Google Tag Manager (noscript) -->';
			}
			
			return $tagCode;
		}
		
		/**
		 * Returns the authentication data for this SDK instance.
		 *
		 * @return array An associative array containing the authentication data.
		 */
		public function getAuthData(): array {
			return $this->auth;
		}
		
		/**
		 * Performs an API request with the specified URL, data, method, and headers.
		 * Checks and renews the authentication token if necessary.
		 *
		 * @param string $url The URL of the API endpoint.
		 * @param array $data The data to send in the API request (optional).
		 * @param string $method The HTTP method to use for the API request (default is 'GET').
		 * @param array $headers The headers to send in the API request (optional).
		 *
		 * @return array An associative array containing the API response status code, success status, and data.
		 *               If the authentication fails, returns an error message with a status code of 401.
		 * @throws Exception
		 */
		private function apiRequest(string $url, array $data = [], string $method = 'GET', array $headers = [], int $attempt = 1): array {
			if ($attempt > self::MAX_AUTH_RETRIES) {
				return ['status_code' => 401, 'success' => false, 'message' => 'Auth error! Max retry limit exceeded!'];
			}
			
			if (!$this->checkAndRenewToken()) {
				return ['status_code' => 401, 'success' => false, 'message' => 'Auth error!'];
			}
			
			if($method === 'GET') {
				$result = $this->curlGet($url, $data, $headers);
			}else{
				$result = $this->curlPost($url, $data, $headers);
			}
			
			if ($result['status_code'] === 401 && ($result['response']['errors'] ?? null) === 'Expired token') {
				// Der Server lehnt den Token ab, obwohl die gespeicherte Laufzeit noch gilt
				// (Uhren-Differenz, rotiertes Secret) - Cache verwerfen und neu authentifizieren.
				$this->clearCachedAuth();
				
				if ($this->checkAndRenewToken(true)) {
					return $this->apiRequest($url, $data, $method, $headers, $attempt + 1);
				}
			}
			
			return $result;
		}
		
		/**
		 * Performs a GET request with the specified URL, query parameters, and headers.
		 *
		 * @param string $url The URL to send the GET request to.
		 * @param array|null $query The query parameters to include in the GET request (optional).
		 * @param array|null $header The headers to include in the GET request (optional).
		 *
		 * @return array An associative array containing the response data and status code of the GET request.
		 */
		private function curlGet(string $url, ?array $query = [], ?array $header = []): array {
			
			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['token'];
				$header[] = "Content-Type: application/json; charset=UTF-8";
			}
			
			// http_build_query kodiert selbst - ein vorheriges urlencode() ergaebe %253A statt %3A.
			$query['ip']       = $_SERVER['REMOTE_ADDR'] ?? '';
			$query['language'] = $this->filter['language'];
			
			$separator = strpos($url, '?') !== false ? '&' : '?';
			
			$curl = curl_init($this->endpoint($url) . $separator . http_build_query($query));
			
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
			curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
			
			return $this->execute($curl);
		}
		
		/**
		 * Performs a POST request with the specified URL, data, and headers.
		 *
		 * @param string $url The URL to send the POST request to.
		 * @param array|null $header The headers to include in the POST request (optional).
		 * @param array $data The data to send in the POST request (optional).
		 *
		 * @return array An associative array containing the response data and status code of the POST request.
		 */
		private function curlPost(string $url, array $data = [], ?array $header = []): array {
			
			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['token'];
				$header[] = "Content-Type: application/json; charset=UTF-8";
			}
			
			$dataString = json_encode($data);
			
			$curl = curl_init($this->endpoint($url));
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
			curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
			curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
			
			return $this->execute($curl);
		}
		
		/**
		 * Prints the specified data in a human-readable format and stops the execution of the script.
		 *
		 * @param mixed $data The data to print.
		 *
		 * @return void This method does not return a value, but it stops the execution of the script.
		 */
		public function printH($data): void {
			echo "<pre>";
			print_r($data);
			echo "</pre>";
			die;
		}
		
		
		
		/**
		 * Baut die vollstaendige Endpunkt-URL.
		 *
		 * @param string $path The endpoint path.
		 *
		 * @return string
		 */
		private function endpoint(string $path): string {
			return rtrim($this->scope_url, '/') . '/' . ltrim($path, '/');
		}

		/**
		 * Fuehrt einen vorbereiteten cURL-Handle aus und dekodiert die Antwort.
		 *
		 * @param resource|\CurlHandle $curl The prepared cURL handle.
		 *
		 * @return array ['response' => array|null, 'status_code' => int, 'error' => string|null]
		 */
		private function execute($curl): array {
			$response  = curl_exec($curl);
			$httpCode  = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$curlError = curl_errno($curl) !== 0 ? curl_error($curl) : null;

			if ($curlError !== null) {
				return ['response' => null, 'status_code' => 0, 'error' => $curlError];
			}

			$decoded = is_string($response) ? json_decode($response, true) : null;

			return [
				'response'    => is_array($decoded) ? $decoded : null,
				'status_code' => $httpCode,
				'error'       => is_array($decoded) ? null : 'Malformed response body.',
			];
		}

		/**
		 * Beschreibt einen fehlgeschlagenen Request fuer die Fehlermeldung.
		 *
		 * @param array $result The result of execute().
		 *
		 * @return string
		 */
		private function describeFailure(array $result): string {
			return (string)($result['response']['message']
				?? $result['error']
				?? 'HTTP ' . $result['status_code']);
		}

		/**
		 * Kodiert einen Wert als JS-String-Literal inklusive Anfuehrungszeichen.
		 *
		 * @param string $value The value to encode.
		 *
		 * @return string
		 */
		private function jsString(string $value): string {
			return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: "''";
		}

		/**
		 * Liest gueltige Auth-Daten aus der Session.
		 *
		 * @return array|null
		 */
		private function readCachedAuth(): ?array {
			if (session_status() !== PHP_SESSION_ACTIVE) {
				return null;
			}

			$auth = $_SESSION['HEY_AUTH'] ?? null;

			if (!is_array($auth) || empty($auth['token']) || (int)($auth['expiration'] ?? 0) <= time()) {
				return null;
			}

			return $auth;
		}

		/**
		 * Legt die Auth-Daten in der Session ab, sofern eine Session laeuft.
		 *
		 * @param array $auth The auth data to cache.
		 *
		 * @return void
		 */
		private function writeCachedAuth(array $auth): void {
			if (session_status() === PHP_SESSION_ACTIVE) {
				$_SESSION['HEY_AUTH'] = $auth;
			}
		}

		/**
		 * Verwirft die zwischengespeicherten Auth-Daten.
		 *
		 * @return void
		 */
		private function clearCachedAuth(): void {
			if (session_status() === PHP_SESSION_ACTIVE) {
				unset($_SESSION['HEY_AUTH']);
			}
		}
	}
