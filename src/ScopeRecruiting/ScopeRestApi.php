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
	use ReallySimpleJWT\Token;

	//require 'vendor/autoload.php';

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
		 * Holds company (client) and applicant auth information.
		 *
		 * array['company']         array Holds client auth token, expiration date and company data.
		 *      ['applicant']       array Holds applicant auth token, expiration date and applicant data.
		 *
		 *
		 * @var array $auth (See above)
		 *
		 */
		protected $auth = [
			'company'   => [],
			'applicant' => []
		];

		/**
		 * Auth configuration for company and applicant.
		 *
		 * array['company']                     array Holds the company auth for requesting a authorization token.
		 *          ['timestamp']               int Timestamp of the auth request.
		 *          ['client_id']               int The company id of a registered SCOPE client.
		 *          ['client_secret']           string The company secret.
		 *      ['applicant']                   array Holds the company auth for requesting a authorization token.
		 *          ['timestamp']               int Timestamp of the auth request.
		 *          ['applicant_email']         string E-Mail address of an applicant that has applied by the company.
		 *
		 *
		 * @var array $auth_config (See above)
		 *
		 */
		protected $auth_config = [
			'company'   => [
				'timestamp'     => null,
				'client_id'     => null,
				'client_secret' => null
			],
			'applicant' => [
				'timestamp'       => null,
				'applicant_email' => null,
			]
		];

		/**
		 * Job filter data submitted with get jobs request.
		 *
		 * array['id']                  int Job id.
		 *      ['applicant_id']        string Applicant id. When given and found, form questions are pre filled with applicant data.
		 *      ['language']            string The language shortcut for strings to be returned from scope.
		 *      ['location']            int Company location id.
		 *      ['department']          string Job department (e.g. Software development).
		 *      ['employment']          string Job employment (e.g. full time, part time)
		 *      ['address']             string Job address.
		 *      ['internal_title']      string Internal job title.
		 *
		 *
		 * @var array $filter (See above)
		 *
		 */
		private $filter = [
			'id'             => [],
			'applicant_id'   => null,
			'language'       => 'de',
			'location'       => [],
			'department'     => [],
			'employment'     => [],
			'address'        => null,
			'internal_title' => []
		];

		/**
		 * SCOPE API request urls.
		 *
		 * array['company_auth']        string Company authentication and requesting an authorization token url.
		 *      ['applicant_auth']      string Applicant authentication and requesting an authorization token url.
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
			'company_auth'     => 'rest_companies/auth',
			'applicant_auth'   => 'rest_applicants/auth',
			'get_company'      => 'rest_companies/view',
			'get_jobs'         => 'rest_jobs/index',
			'get_job'          => 'rest_jobs/view',
			'add_applicant'    => 'rest_applicants/add',
			'upload_documents' => 'rest_applicants/upload',
			'delete_documents' => 'rest_applicants/deleteFile',
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

			$this->setCompanyAuthConfig($config);
			$this->authenticateCompany();
			$this->setCurrentPageURL();
		}

		/**
		 *  Sets company auth data for requesting an JWT access token
		 *
		 *  array['SCOPE_CLIENT_ID']         int The company id of a registered SCOPE client.
		 *       ['SCOPE_CLIENT_SECRET']     string Applicant authentication and requesting an authorization token url.
		 *
		 * @return void
		 *
		 */
		public function setCompanyAuthConfig(array $config): void {
			if(!isset($config['SCOPE_CLIENT_ID'])) {
				throw new InvalidArgumentException('Missing SCOPE_CLIENT_ID parameter.');
			}
			if(!isset($config['SCOPE_CLIENT_SECRET'])) {
				throw new InvalidArgumentException('Missing SCOPE_CLIENT_SECRET parameter.');
			}

			$this->auth_config['company'] = [
				'client_id'     => $config['SCOPE_CLIENT_ID'],
				'client_secret' => $config['SCOPE_CLIENT_SECRET']
			];
		}

		/**
		 *  Sets tracking information (analytics array) to a visitors cookie.
		 *
		 * @return void
		 *
		 */
		public function setAnalyticsCookie(): void {
			setcookie("scope_analytics[current_page]", json_encode($this->analytics['current_page']), strtotime('+24 hours'));
			setcookie("scope_analytics[referrer]", json_encode($this->analytics['referrer']), strtotime('+24 hours'));
		}

		/**
		 *  Sets the job filter data submitted with get jobs request
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

			$pageURL .= "://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];

			$this->analytics['current_page'] = parse_url($pageURL) + $this->analytics['current_page'];
		}

		/**
		 *  Sets the current referrer url for analytics
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
		 *  Swaps a client_id and client_secret for an JWT auth token and gets the company data
		 *
		 * @return array
		 * @throws Exception
		 *
		 */
		public function authenticateCompany(): array {
			$curl = curl_init($this->scope_url . $this->url['company_auth']);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $this->auth_config['company']);

			$result = json_decode($this->removeUtf8Bom(curl_exec($curl)), true);

			if($result['success'] && $this->validateToken($result['token'])) {
				$this->auth['company']['token'] = $result['token'];
				$this->auth['company']['data']  = $result['data'];

				return $result;
			}

			throw new Exception('Auth error! Message from Scope: ' . $result['error']['message']);
		}

		/**
		 *  Checks if the JWT token is valid.
		 *
		 * @return bool
		 *
		 */
		public function validateToken(?string $token): bool {
			$result = Token::validate($token, $this->auth_config['company']['client_secret']);

			return $result;
		}

		/**
		 *  Find jobs from SCOPE based on the pre defined filter values.
		 *
		 * @return array
		 *
		 */
		public function getJobs(): array {
			return $this->curlGet($this->url['get_jobs'], null, $this->filter);
		}

		/**
		 *  Get one job from SCOPE.
		 *
		 * @param int    $jobId             : The ID of the job to get from SCOPE.
		 * @param int    $companyLocationId : The ID of the company location that belongs to the job.
		 * @param string $lng               : The language shortcut.
		 *
		 * @return array
		 * @throws Exception
		 */
		public function getJob(?int $jobId = null, ?int $companyLocationId = null, string $lng = ''): array {

			if(empty($jobId)) {
				throw new Exception('Missing job id parameter');
			}
			if(empty($companyLocationId)) {
				throw new Exception('Missing company location id parameter');
			}

			$lng = empty($lng) ? $this->filter['language'] : $lng;

			$url = $this->url['get_job'] . '/' . $jobId . '/' . $companyLocationId . '/' . $lng;

			return $this->curlGet($url, null);
		}

		public function saveApplicant(array $data, int $jobId, int $companyLocationId): array {
			$response = $this->curlPost($this->url['add_applicant'] . '/' . $jobId . '/' . $companyLocationId, null, $data);

			if(!$response['success']) {
				if(!in_array($response['error']['detail'], ['Duplicate application', 'Validation error'])) {
					throw new Exception('Error while trying to  save applicant. Url: ' . $this->url['add_applicant'] . '/' . $jobId . ' Error message: ' . $response['error']['message']);
				}
			}

			return $response;
		}

		/*
		 * This function should only be called if you want to upload the applicant documents before submitting his personal data.
		 * After the first successful upload, the api will response with the created applicantId.
		 * If you want to upload a second document, use the applicantId.
		 */
		public function uploadDocumentBeforeApplicantData($data = null, $documentType = null, $jobId = null, $applicantId = null, $companyLocationId = null) {
			$result = [
				'success' => false
			];

			if(!empty($data) && !empty($documentType) && !empty($jobId) && !empty($companyLocationId)) {

				if(!$this->validateToken()) {
					$this->authenticateCompany();
				}

				$dataString = json_encode($data);

				$headr[] = "Token: " . $this->auth['company']['token'];
				$headr[] = "Content-Type: application/json";

				$url = !empty($applicantId)
					? $this->scope_url . $this->url['upload_documents'] . '/' . $documentType . '/' . $jobId . '/' . $companyLocationId . '/' . $applicantId
					: $this->scope_url . $this->url['upload_documents'] . '/' . $documentType . '/' . $jobId . '/' . $companyLocationId;

				$curl = curl_init($url);

				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
				curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);
				curl_setopt($curl, CURLOPT_HTTPHEADER, $headr);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

				$result = json_decode(curl_exec($curl), true);

				curl_close($curl);
			}

			return $result;
		}

		public function deleteDocument($name = null, $applicantId = null, $companyLocationId = null, $docType = null) {

			$result = [
				'success' => false
			];

			if(!empty($name) && !empty($applicantId) && !empty($docType)) {

				if(!$this->validateToken($this->auth['company']['token'])) {
					$this->authenticateCompany();
				}

				$dataString = json_encode([]);

				$headr[] = "Token: " . $this->auth['company']['token'];
				$headr[] = "Content-Type: application/json";

				$url = $this->scope_url . $this->url['delete_documents'] . '/' . $name . '/' . $applicantId . '/' . $companyLocationId . '/' . $docType;

				$curl = curl_init($url);

				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
				curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);
				curl_setopt($curl, CURLOPT_HTTPHEADER, $headr);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

				$result = json_decode(curl_exec($curl), true);
				curl_close($curl);
			}

			return $result;
		}

		public function getGoogleTagCode(): array {
			$tagCode = [
				'head' => '',
				'body' => ''
			];

			if(isset($this->auth['company']['data']['CompanySetting'])) {
				if(!empty($this->auth['company']['data']['CompanySetting']['google_tag_public_id'])) {
					$tagCode['head'] =
						"<!-- Google Tag Manager -->" .
						"<script> (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start': " .
						"new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0], " .
						"j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src= " .
						"'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f); " .
						"})(window,document,'script','dataLayer', '" .
						$this->auth['company']['data']['CompanySetting']['google_tag_public_id'] . "');</script> " .
						"<!-- End Google Tag Manager -->";

					$tagCode['body'] =
						'<!-- Google Tag Manager (noscript) -->' .
						'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' .
						$this->auth['company']['data']['CompanySetting']['google_tag_public_id'] . '" ' .
						'height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript> ' .
						'<!-- End Google Tag Manager (noscript) -->';
				} elseif($this->analytics['active']) {
					throw new Exception('Analytics is set to true but no GTM public id found in company settings.');
				}
			} elseif($this->analytics['active']) {
				throw new Exception('Auth company data is empty.');
			}

			return $tagCode;
		}

		public function getCompanyAuthData(): array {
			return $this->auth['company'];
		}

		public function getApplicantAuthData(): array {
			return $this->auth['applicant'];
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
							$list[strtolower($v)] = $v;
						}
					}
				}
			}

			return $list;
		}

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

			if(!$this->validateToken($this->auth['company']['token'])) {
				$this->authenticateCompany();
			}

			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['company']['token'];
				$header[] = "Content-Type: application/json; charset: UTF-8";
			}

			$query['ip'] = urlencode($_SERVER['REMOTE_ADDR']);

			$queryData = http_build_query($query);

			$curl = curl_init($this->scope_url . $url . '?' . $queryData);

			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

			$result = json_decode($this->removeUtf8Bom(curl_exec($curl)), true);

			curl_close($curl);

			return $result;
		}

		private function curlPost(string $url, ?array $header = [], array $data = []): array {

			if(!$this->validateToken($this->auth['company']['token'])) {
				$this->authenticateCompany();
			}

			if($this->analytics['active']) {
				$data += $this->analytics;
				unset($data['active']);
			}

			$dataString = json_encode($data);

			if(empty($header)) {
				$header[] = "Authorization: Bearer " . $this->auth['company']['token'];
				$header[] = "Content-Type: application/json; charset: UTF-8";
			}

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

		/*public function setApplicantAuthConfig(string $email, string $password): void {
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
