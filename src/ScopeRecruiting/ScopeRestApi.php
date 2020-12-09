<?php

	/**
	 * Class ScopeRestApi
	 *
	 * A sample class to communicate with the scope-recruiting rest api
	 *
	 * @author        Oleg Mutzenberger
	 * @email         oleg@artrevolver.de
	 * @web           https://scope-recruiting.de
	 * @copyright     Copyright 2019, Reinhart, Neumann und Mutzenberger GbR
	 * @license       http://opensource.org/licenses/mit-license.php MI
	 *
	 */

	declare(strict_types=1);

	namespace ScopeRecruiting;

	use Exception;
	use InvalidArgumentException;

	/**
	 * Class ScopeRestApi
	 *
	 */
	class ScopeRestApi {

		/**
		 * API request url.
		 *
		 * @var string $scope_url
		 */
		public $scope_url;

		/**
		 * Holds visitor tracking information.
		 *
		 * array['active']          bool Defines weather visitor tracking is active. When set to true, tracking is handled via Google Tag Manager (GTM).
		 *      ['current_page']    array Holds url_parse() information of client url.
		 *      ['referrer']        array Holds url_parse() information of client referrer.
		 *
		 *
		 * @var array $analytics (See above)
		 *
		 */
		public $analytics = [
			'active'       => true,
			'current_page' => [],
			'referrer'     => [],
		];

		/**
		 * @var $auth array  Holds auth information.
		 *
		 */
		protected $auth = [
			'token' => null
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
		protected $auth_config = [
			'client_id'     => null,
			'client_secret' => null
		];

		/**
		 * Job filter data submitted with get jobs request.
		 *
		 * array['id']                      int Job id.
		 *      ['applicant_id']            string Applicant id. When given and found, form questions are pre filled with applicant data.
		 *      ['language']                string The language shortcut for strings to be returned from scope.
		 *      ['location']                int Company location id.
		 *      ['department']              string Job department (e.g. Software development).
		 *      ['employment']              string Job employment (e.g. full time, part time)
		 *      ['address']                 string Job address.
		 *      ['area_search_distance']    int Area search distance for address. Default 60000 => 60 km
		 *      ['internal_title']          string Internal job title.
		 *
		 *
		 * @var array $filter (See above)
		 *
		 */
		private $filter = [
			'id'                   => [],
			'applicant_id'         => null,
			'language'             => 'de',
			'location'             => [],
			'department'           => [],
			'employment'           => [],
			'internal_title'       => [],
			'address'              => null,
			'area_search_distance' => 60000, // 60 km
			'preview'              => false
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
		 *
		 */
		private $url = [
			'auth'                      => 'rest_accounts/auth',
			'get_company'               => 'rest_companies/view',
			'get_company_by_sub_domain' => 'rest_companies/viewBySubDomain',
			'get_jobs'                  => 'rest_jobs/index',
			'get_job'                   => 'rest_jobs/view',
			'add_applicant'             => 'rest_applicants/add',
			'upload_documents'          => 'rest_applicants/uploadDocument',
			'delete_documents'          => 'rest_applicants/deleteDocument',
		];

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

			$this->analytics['current_page'] = $urlObject;
			$this->analytics['referrer']     = $urlObject;

			$this->setAuthConfig($config);
			$this->authenticate();
			$this->setCurrentPageURL();
		}

		/**
		 *  Sets auth data for requesting an JWT access token
		 *
		 * @param $config        array ['SCOPE_CLIENT_ID']         int The client id of a registered SCOPE client.
		 *                       ['SCOPE_CLIENT_SECRET']     string The client secret of a registered SCOPE client.
		 *
		 * @return void
		 *
		 */
		public function setAuthConfig(array $config): void {
			if(!isset($config['SCOPE_CLIENT_ID'])) {
				throw new InvalidArgumentException('Missing SCOPE_CLIENT_ID parameter.');
			}
			if(!isset($config['SCOPE_CLIENT_SECRET'])) {
				throw new InvalidArgumentException('Missing SCOPE_CLIENT_SECRET parameter.');
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
		 *
		 */
		public function authenticate(): array {
			$curl = curl_init($this->scope_url . $this->url['auth']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $this->auth_config);

			$result = json_decode($this->removeUtf8Bom(curl_exec($curl)), true);

			if($result['success']) {
				$this->auth['token'] = $result['token'];

				return $result;
			}

			throw new Exception('Auth error! Message from Scope: ' . $result['error']['message']);
		}

		/**
		 *  Sets tracking information (analytics array) to a visitors cookie.
		 *
		 * @return void
		 *
		 */
		public function setAnalyticsCookie(): void {
			setcookie("scope_analytics[current_page]", json_encode($this->analytics['current_page']), strtotime('+24 hours'), '/');
			setcookie("scope_analytics[referrer]", json_encode($this->analytics['referrer']), strtotime('+24 hours'), '/');
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
				parse_str($qs, $new_GET);
				// Replace only the wanted keys
				$this->filter = array_replace($this->filter, array_intersect_key($new_GET, $this->filter));
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
			if(!empty($url) && strpos($url, $this->analytics['current_page']['host']) === false) {
				$this->analytics['referrer'] = parse_url($url) + $this->analytics['referrer'];
			}
		}

		/**
		 *  Get company data.
		 *
		 * @param $companyId int|null
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getCompany(?int $companyId = null): array {

			$url = !empty($companyId) ? $this->url['get_company'] . '/' . $companyId : $this->url['get_company'];

			$result = $this->curlGet($url, null);

			if($result['status_code'] === 401) {

				if($this->authenticate()['success']) {
					$this->getCompany($companyId);
				}
			}

			return $result;
		}

		/**
		 *  Get company data by sub domain
		 *
		 * @param $subDomain string
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getCompanyBySubDomain(string $subDomain = ''): array {

			$result = $this->curlGet($this->url['get_company_by_sub_domain'] . '/' . $subDomain, null);

			if($result['status_code'] === 401) {

				if($this->authenticate()['success']) {
					$this->getCompanyBySubDomain($subDomain);
				}
			}

			return $result;
		}

		/**
		 *  Find jobs from SCOPE based on the pre defined filter values.
		 *
		 * @param $companyId int|null
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJobs(?int $companyId = null): array {
			$url = !empty($companyId) ? $this->url['get_jobs'] . '/' . $companyId : $this->url['get_jobs'];

			$result = $this->curlGet($url, null, $this->filter);

			if($result['status_code'] === 401) {

				if($this->authenticate()['success']) {
					$this->getJobs();
				}
			}

			return $result;
		}

		/**
		 *  Get one job from SCOPE.
		 *
		 * @param int $jobId             : The ID of the job to get from SCOPE.
		 * @param int $companyLocationId : The ID of the company location that belongs to the job.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJob(?int $jobId = null, ?int $companyLocationId = null): array {

			if(empty($jobId)) {
				throw new Exception('Missing job id parameter');
			}
			if(empty($companyLocationId)) {
				throw new Exception('Missing company location id parameter');
			}

			$url = $this->url['get_job'] . '/' . $jobId . '/' . $companyLocationId . '?preview=' . $this->filter['preview'];

			$result = $this->curlGet($url, null);

			if($result['status_code'] === 401) {
				if($this->authenticate()['success']) {
					$this->getJob($jobId, $companyLocationId);
				}
			}

			return $result;
		}

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

		public function getAuthData(): array {
			return $this->auth;
		}

		public function getFormattedAddress(array $companyLocation = [], bool $street = true, bool $city = true, bool $country = true): string {
			$address = '';

			if(!empty($companyLocation)) {

				if($street && !empty($companyLocation['CompanyLocation']['street'])) {
					$address = $companyLocation['CompanyLocation']['street'];
				}

				if($street && !empty($companyLocation['CompanyLocation']['street_number'])) {
					$address .= ' ' . $companyLocation['CompanyLocation']['street_number'];
				}

				if(($city && !empty($companyLocation['CompanyLocation']['city'])) || ($street && empty($companyLocation['CompanyLocation']['street'] && !empty($companyLocation['CompanyLocation']['city'])))) {
					$address .= $address !== '' ? ', ' . $companyLocation['CompanyLocation']['city'] : $companyLocation['CompanyLocation']['city'];
				}
				if($country && !empty($companyLocation['CompanyLocation']['country']) || (empty($companyLocation['CompanyLocation']['street']) && empty($companyLocation['CompanyLocation']['city']))) {
					$address .= $address !== '' ? ', ' . $companyLocation['CompanyLocation']['country'] : $companyLocation['CompanyLocation']['country'];
				}
			}

			return $address;
		}

		public function getEmploymentTypeList(array $jobs = []): array {
			$list = [];
			foreach($jobs as $key => $value) {

				if(!empty($value['Job']['employment'])) {

					$employments = explode(',', $value['Job']['employment']);
					$employments = is_array($employments) ? $employments : [$employments];

					foreach($employments as $k => $v) {
						if(!in_array($v, $list)) {
							$list[trim(strtolower($v), " ")] = $v;
						}
					}
				}
			}
			return $list;
		}

		public function getCurrentPageURL(string $part = 'full'): string {
			$url = $part === 'full'
				? $this->analytics['current_page']['scheme'] . '://' .
				  $this->analytics['current_page']['host'] .
				  $this->analytics['current_page']['path'] .
				  $this->analytics['current_page']['query']
				: $this->analytics['current_page'][$part];

			return $url;
		}

		public function printH($data): void {
			echo "<pre>";
			print_r($data);
			echo "</pre>";
			die;
		}

		private function curlGet(string $url, ?array $header = [], ?array $query = []): array {

			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['token'];
				$header[] = "Content-Type: application/json; charset: UTF-8";
			}

			$query['ip']       = urlencode($_SERVER['REMOTE_ADDR']);
			$query['language'] = $this->filter['language'];

			$queryData = http_build_query($query);

			$query = strpos($url, '?') !== false ? '&' . $queryData : '?' . $queryData;

			$curl = curl_init($this->scope_url . $url . $query);

			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			$result = json_decode($this->removeUtf8Bom(curl_exec($curl)), true);

			curl_close($curl);

			return $result;
		}

		private function curlPost(string $url, ?array $header = [], array $data = []): array {

			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['token'];
				$header[] = "Content-Type: application/json; charset: UTF-8";
			}

			$data += $this->analytics;

			$dataString = json_encode($data);

			$curl = curl_init($this->scope_url . $url);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
			curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

			$result = json_decode($this->removeUtf8Bom(curl_exec($curl)), true);

			curl_close($curl);

			return $result;
		}

		// Remove multiple UTF-8 BOM sequences
		private function removeUtf8Bom($text): string {
			$bom  = pack('H*', 'EFBBBF');
			$text = preg_replace("/^$bom/", '', $text);

			return $text;
		}

		/*
		public function build_http_query($query) {

			$query_array = [];

			foreach($query as $key => $key_value) {
				if(is_array($key_value)) {
					foreach($key_value as $k => $v) {
						if(!empty($v) || $v === false) {
							if(is_bool($v)) {
								$v = $v ? 'true' : 'false';
							} else {
								$v = urlencode($v);
							}
							$query_array[] = urlencode($key) . '=' . $v;
						}
					}
				} else {
					if(!empty($key_value) || $key_value === false) {
						if(is_bool($key_value)) {
							$key_value = $key_value ? 'true' : 'false';
						} else {
							$key_value = urlencode($key_value);
						}
						$query_array[] = urlencode($key) . '=' . $key_value;
					}
				}
			}

			return implode('&', $query_array);
		}
		 public function setApplicantAuthConfig(string $email, string $password): void {
			if(empty($email)) {
				throw new InvalidArgumentException('Missing email parameter.');
			}
			if(empty($password)) {
				throw new InvalidArgumentException('Missing password parameter.');
			}

			$stamp          = strtotime('now');
			$hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['salt' => 'rgfoijewjf8273487bnfew']);

			echo '###' . $hashedPassword . '###';

			$signature = hash_hmac('SHA256', (string) $stamp, $hashedPassword);

			$this->auth_config['applicant'] = [
				'applicant_email'     => $email,
				'applicant_signature' => $signature,
				'timestamp'           => $stamp
			]
		};


		public function authenticateApplicant(): array {
			if(!$this->isTokenExpired()) {

				$dataString = json_encode($this->auth_config['applicant']);

				$url = $this->scope_url . $this->url['applicant_auth'];

				$headr[] = "Token: " . $this->auth['company']['token'];
				$headr[] = "Content-Type: application/json; charset=utf-8";

				$curl = curl_init($url);

				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
				curl_setopt($curl, CURLOPT_HTTPHEADER, $headr);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);

				$result = json_decode($this->removeUtf8Bom(curl_exec($curl)), true);

				curl_close($curl);

				if($result['success']) {
					$this->auth['applicant']['token']          = $result['token'];
					$this->auth['applicant']['expiration'] = $result['expiration_date'];
					$this->auth['applicant']['data']           = $result['data'];

					return $result;
				}

				throw new \Exception('Auth error! Message from Scope: ' . $result['error']['message']);
			}

			throw new Exception('Token has expired!');
		}

		*/
	}
