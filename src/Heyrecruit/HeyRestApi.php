<?php
	/**
	 * Class ScopeRestApi
	 *
	 * A sample class to communicate with the heyrecruit rest api
	 *
	 * @author        Oleg Mutzenberger
	 * @email         oleg@artrevolver.de
	 * @web           https://scope-recruiting.de
	 * @copyright     Copyright 2023, Artrevolver GmbH
	 * @license       http://opensource.org/licenses/mit-license.php MI
	 *
	 */
	declare(strict_types=1);
	
	namespace Heyrecruit;
	
	use Exception;
	use InvalidArgumentException;
	
	/**
	 * Class ScopeRestApi
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
		 * Holds visitor tracking information.
		 *
		 * array    ['active']          Defines weather visitor tracking is active.
		 *                              When set to true, tracking is handled via Google Tag Manager (GTM).
		 *          ['current_page']    Holds url_parse() information of client url.
		 *          ['referrer']        Holds url_parse() information of client referrer.
		 *
		 *
		 * @var array $analytics (See above)
		 *
		 */
		public array $analytics = [
			'active'       => true,
			'current_page' => [],
			'referrer'     => [],
		];
		
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
			'internal_titles'       => [],
			'language'             => 'de',
			'search'               => null,
			'address'              => null,
			'area_search_distance' => 60000, // 60 km
			'limit'                => 999,
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
			'add_applicant'             => 'rest-applicants/add',
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
			
			if(isset($config['GA_TRACKING'])) {
				$this->analytics['active'] = filter_var($config['GA_TRACKING'], FILTER_VALIDATE_BOOLEAN);
			}
			
			$this->scope_url = $config['SCOPE_URL'];
			
			$urlObject = [
				'scheme' => null,
				'host'   => null,
				'path'   => null,
				'query'  => null
			];
			
			if (substr($this->scope_url, -1) === "/") {
				$this->scope_url = rtrim($this->scope_url, "/");
			}
			
			$this->analytics['current_page'] = $urlObject;
			$this->analytics['referrer']     = $urlObject;
			
			$this->setAuthConfig($config);
			$this->authenticate();
			$this->setCurrentPageURL();
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
		public function authenticate(): array {
			$auth = $_SESSION['HEY_AUTH'] ?? [];
			if (!empty($auth) && $auth['expiration'] > time()) {
				$this->auth = $auth;
				return ['status' => 'OK', 'data' => $auth];
			}
			
			$curl = curl_init($this->scope_url . DS . $this->url['auth']);
			
			curl_setopt_array($curl, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTFIELDS => $this->auth_config
			]);
			
			$response = curl_exec($curl);
			
			$result = json_decode($response, true);
			curl_close($curl);
			
			if ($result['status'] === 'OK') {
				$this->auth = $result['data'];
				$_SESSION['HEY_AUTH'] = $this->auth;
				return $result;
			}
			
			throw new Exception('Auth error! Message from Heyrecruit: ' . $result['message']);
		}
		
		/**
		 * Checks whether the current access token has expired and renews it if necessary.
		 *
		 * @return bool True if the access token is still valid or has been successfully renewed, false otherwise.
		 *
		 * @throws Exception if an error occurs while authenticating or renewing the access token.
		 */
		private function checkAndRenewToken(): bool {
			if ($this->auth['expiration'] < time()) {
				$authResult = $this->authenticate();
				
				if ($authResult['status'] === 'OK') {
					$this->auth['token']      = $authResult['data']['token'];
					$this->auth['expiration'] = $authResult['data']['expiration'] - 60;
					return true;
				}
				
				return false;
			}
			
			return true;
		}
		
		/**
		 *  Sets tracking information (analytics array) to a visitors' cookie.
		 *
		 * @return void
		 */
		public function setAnalyticsCookie(): void {
			if (version_compare(PHP_VERSION, '7.3.0') >= 0) {
				$arr_cookie_options = [
					'expires'  => time() + 3600,
					'path'     => '/',
					'secure'   => true,     // or false
					'httponly' => false,    // or false
					'samesite' => 'None' // None || Lax  || Strict
				];
				setcookie("scope_analytics[current_page]", json_encode($this->analytics['current_page']), $arr_cookie_options);
				setcookie("scope_analytics[current_page]", json_encode($this->analytics['current_page']), time() + 3600, "/");
				
				setcookie("scope_analytics[referrer]", json_encode($this->analytics['referrer']), $arr_cookie_options);
				setcookie("scope_analytics[referrer]", json_encode($this->analytics['referrer']), time() + 3600, "/");
			}else{
				setcookie("scope_analytics[current_page]", json_encode($this->analytics['current_page']), time() + 3600, "/; SameSite=None; Secure");
				setcookie("scope_analytics[current_page]", json_encode($this->analytics['current_page']), time() + 3600, "/");
				
				setcookie("scope_analytics[referrer]", json_encode($this->analytics['referrer']), time() + 3600, "/; SameSite=None; Secure");
				setcookie("scope_analytics[referrer]", json_encode($this->analytics['referrer']), time() + 3600, "/");
			}
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
				$this->filter['address'] = !empty($this->filter['address']) ? $this->filter['address'][0] : null;
				$this->filter['preview'] = isset($this->filter['preview']) && $this->filter['preview'] == true ? 1 : 0;
			}
		}
		
		/**
		 *  Sets the current page url for analytics
		 *
		 * @return void
		 *
		 */
		public function setCurrentPageURL(): void {
			$pageURL = 'http';
			if($_SERVER["HTTPS"] == "on") {
				$pageURL .= "s";
			}
			
			if(isset($_SERVER["HTTP_HOST"])) {
				$pageURL .= "://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
			} else {
				$pageURL .= "://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
			}
			
			$this->analytics['current_page'] = parse_url($pageURL) + $this->analytics['current_page'];
		}
		
		/**
		 *  Sets the current referrer url for analytics
		 *
		 * @param $url  String|NULL
		 *
		 * @return void
		 *
		 */
		public function setReferrer(?string $url): void {
			if(!empty($url)) {
				$this->analytics['referrer'] = parse_url($url) + $this->analytics['referrer'];
			}
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
		public function getJobs(int $companyId = null): array {
			$url =  $this->url['get_jobs'];
			
			$this->filter['company'] = $companyId;
			
			return $this->apiRequest($url, $this->filter);
		}
		
		/**
		 *  Get one job.
		 *
		 * @param int|null $jobId
		 * @param int|null $companyLocationId
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJob(int $companyId = null, int $jobId, int $companyLocationId): array {
			
			$url =  $this->url['get_job'];
			
			return $this->apiRequest($url, [
				'company'          => $companyId,
				'job_id'              => $jobId,
				'company_location_id' => $companyLocationId,
			]);
			
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
			
			if($this->analytics['active'] && !empty($publicId)) {
				$tagCode['head'] =
					"<!-- Google Tag Manager -->" .
					"<script> (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start': " .
					"new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0], " .
					"j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src= " .
					"'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f); " .
					"})(window,document,'script','dataLayer', '" .
					$publicId . "');</script> " .
					"<!-- End Google Tag Manager -->";
				
				$tagCode['body'] =
					'<!-- Google Tag Manager (noscript) -->' .
					'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' .
					$publicId . '" ' .
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
		 * Get the current page URL.
		 *
		 * @param string $part The part of the URL to retrieve ('full', 'scheme', 'host', 'path', or 'query').
		 *                     Defaults to 'full'.
		 *
		 * @return string The requested part of the current page URL.
		 */
		public function getCurrentPageURL(string $part = 'full'): string {
			$url = $part === 'full'
				? $this->analytics['current_page']['scheme'] . '://' .
				$this->analytics['current_page']['host'] .
				$this->analytics['current_page']['path'] .
				$this->analytics['current_page']['query']
				: $this->analytics['current_page'][$part];
			
			return $url;
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
		private function apiRequest(string $url, array $data = [], string $method = 'GET', array $headers = []): array {
			if (!$this->checkAndRenewToken()) {
				return ['status_code' => 401, 'success' => false, 'message' => 'Auth error!'];
			}
			
			if($method === 'GET') {
				$result = $this->curlGet($url, $data, $headers);
			}else{
				$result = $this->curlPost($url, $data, $headers);
			}
			
			if ($result['status_code'] === 401 && $this->checkAndRenewToken()) {
				return $this->apiRequest($url, $data, $method, $headers);
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
				$header[] = "Content-Type: application/json; charset: UTF-8";
			}
			
			$query['ip']       = urlencode($_SERVER['REMOTE_ADDR']);
			$query['language'] = $this->filter['language'];
			
			$queryData = http_build_query($query);
			
			$query = strpos($url, '?') !== false ? '&' . $queryData : '?' . $queryData;
			
			$curl = curl_init($this->scope_url . DS . $url . $query);
			
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			
			$response = curl_exec($curl);
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			
			$result = json_decode($response, true);
			
			curl_close($curl);
			
			return array(
				'response'    => $result,
				'status_code' => $httpCode,
			);
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
		private function curlPost(string $url, ?array $header = [], array $data = []): array {
			
			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['token'];
				$header[] = "Content-Type: application/json; charset: UTF-8";
			}
			
			$data += $this->analytics;
			
			$dataString = json_encode($data);
			
			$curl = curl_init($this->scope_url . DS . $url);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
			curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			
			$response = curl_exec($curl);
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			
			$result = json_decode($response, true);
			
			curl_close($curl);
			
			return array(
				'response'    => $result,
				'status_code' => $httpCode,
			);
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
		
		
		
		/*
		public function saveApplicant(array $data, int $jobId, int $companyLocationId): array {
			$result = $this->curlPost($this->url['add_applicant'] . '/' . $jobId . '/' . $companyLocationId, null, $data);
			
			if($result['status_code'] === 401) {
				if($this->authenticate()['success']) {
					$this->saveApplicant($data, $jobId, $companyLocationId);
				}
			}
			
			return $result;
		}
		
		/**
		 * Uploads an applicant documents before or after submitting his data.
		 * After the first successful upload, the api will response with the created applicantId.
		 * If you want to upload a second document, use the applicantId
		 *
		 * @param array  $data              : The document data.
		 * @param string $documentType      : The document type (picture | covering_letter | cv | certificate | other).
		 * @param int    $jobId             : The job ID.
		 * @param int    $companyLocationId : The ID of the company location that belongs to the job.
		 * @param string $applicantId       : The applicant ID.
		 *
		 * @return array
		 * @throws Exception
		 */
		
		/*
		public function uploadDocument(array $data, string $documentType, int $jobId, int $companyLocationId, string $applicantId = '') {
			
			if(empty($data)) {
				throw new Exception('Missing applicant data');
			}
			if(empty($documentType)) {
				throw new Exception('Missing document type parameter');
			}
			if(empty($jobId)) {
				throw new Exception('Missing job id parameter');
			}
			if(empty($companyLocationId)) {
				throw new Exception('Missing company location id parameter');
			}
			
			$url = !empty($applicantId)
				? $this->scope_url . $this->url['upload_documents'] . '/' . $documentType . '/' . $jobId . '/' . $companyLocationId . '/' . $applicantId
				: $this->scope_url . $this->url['upload_documents'] . '/' . $documentType . '/' . $jobId . '/' . $companyLocationId;
			
			$result = $this->curlPost($url, null, $data);
			
			if($result['status_code'] === 401) {
				if($this->authenticate()['success']) {
					$this->uploadDocument($data, $documentType, $jobId, $companyLocationId, $applicantId);
				}
			}
			
			return $result;
		}
		
		*/
		
		
	}
