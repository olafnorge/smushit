<?php
class SmushitApi {
	
	const API_URL = 'http://www.smushit.com/ysmush.it/';
	const CURL_METHOD_GET = 'get';
	const CURL_METHOD_HEAD = 'head';
	const CURL_METHOD_POST = 'post';
	const REQUEST_TYPE_OPTIMIZE = 'optimize';
	const REQUEST_TYPE_ZIP = 'zip';
	
	/**
	 * Helper to determine if the API was already requested.
	 * @var bool
	 */
	private $_apiRequested = null;
	
	/**
	 * Path where ZIP files will be cached.
	 * @var string
	 */
	private static $_cachePath = null;
	
	/**
	 * Path where cookie files will be cached
	 * @var string
	 */
	private static $_cookieFile = null;
	
	/**
	 * Container for the images.
	 * @var array
	 */
	private $_images = null;
	
	/**
	 * Helper to tell the API if the binary data of the images should requested by default.
	 * @var bool
	 */
	private $_requestBinaryData = null;
	
	/**
	 * Subtask identifier for grouping smushed images.
	 * @var string
	 */
	private $_subTask = null;
	
	/**
	 * Task identifier for grouping smushed images.
	 * @var string
	 */
	private $_task = null;

	
	/**
	 * Initializes the API.
	 */
	public function __construct($requestBinaryData = true) {
		$this->reset($requestBinaryData);
		!self::$_cookieFile && self::$_cookieFile = (php_sapi_name() == 'cli' ? DIRECTORY_SEPARATOR.'tmp' : ini_get('upload_tmp_dir')).DIRECTORY_SEPARATOR.'.smushit_cookie.txt';
		!self::$_cachePath && self::$_cachePath = (php_sapi_name() == 'cli' ? DIRECTORY_SEPARATOR.'tmp' : ini_get('upload_tmp_dir')).DIRECTORY_SEPARATOR;
	}
	
	
	/**
	 * Clears the API
	 */
	public function __destruct() {
		$this->_apiRequested = null;
		$this->_images = null;
		$this->_requestBinaryData = null;
		$this->_subTask = null;
		$this->_task = null;
		is_file(self::$_cookieFile) && unlink(self::$_cookieFile);
	}
	
	
	/**
	 * @param string $id The unique ID of the image
	 * @throws SmushitApiException
	 * @return SmushitImage The requested image
	 */
	public function getImage($id) {
		if (!$this->_apiRequested) {
			throw new SmushitApiException('The API was not requested.');
		}
		
		return isset($this->_images[$id]) ? $this->_images[$id] : null;
	}
	
	
	/**
	 * @throws SmushitApiException
	 * @return int Count of images
	 */
	public function getImageCount() {
		if (!$this->_apiRequested) {
			throw new SmushitApiException('The API was not requested.');
		}
		
		return count($this->_images);
	}
	
	
	/**
	 * @throws SmushitApiException
	 * @return array All images
	 */
	public function getImages() {
		if (!$this->_apiRequested) {
			throw new SmushitApiException('The API was not requested.');
		}
		
		return (array)$this->_images;
	}
	
	
	/**
	 * @return string Subtask identifier for grouping smushed images
	 */
	public function getSubTask() {
		return (string)$this->_subTask;
	}
	
	
	/**
	 * @return string Task identifier for grouping smushed images
	 */
	public function getTask() {
		return (string)$this->_task;
	}
	
	
	/**
	 * @throws SmushitApiException
	 * @return binary A ZIP file containing the smushed images
	 */
	public function getZip($task = null, $subTask = null) {
		if (!is_file(self::$_cachePath.$task.'.zip') && !$this->_apiRequested) {
			throw new SmushitApiException('The API was not requested. You have to request the API first before requesting the ZIP file.');
		}
		
		return $this->_requestApi(self::REQUEST_TYPE_ZIP, false, $task ? $task : null, $subTask ? $subTask : null);
	}
	
	
	/**
	 * @param array $files Files that are going to be optimzed
	 * @throws SmushitApiException
	 * @return bool True if API call was successful false otherwise
	 */
	public function optimize(array $files, $createZip = false) {
		if ($this->_apiRequested) {
			throw new SmushitApiException('The API was already requested. Please use an new object or reset the API.');
		}
		
		foreach ($files as $file) {
			/* @var $SmushitImage SmushitImage */
			$SmushitImage = new SmushitImage($file, $this->getTask());
			$this->_images[$SmushitImage->getId()] = $SmushitImage;
		}
		
		return $this->_requestApi(self::REQUEST_TYPE_OPTIMIZE, $createZip);
	}
	
	
	/**
	 * @param bool $requestBinaryData
	 */
	public function reset($requestBinaryData = true) {
		$this->_apiRequested = false;
		$this->_images = array();
		$this->_requestBinaryData = $requestBinaryData;
		$this->_subTask = null;
		$this->_task = uniqid();
		is_file(self::$_cookieFile) && unlink(self::$_cookieFile);
	}
	
	
	/**
	 * @param string $src
	 * @param bool $isRemoteRequest
	 * @throws SmushitApiException
	 * @return binary Requested binary data
	 */
	private function _getBinaryData($src, $isRemoteRequest) {
		$binaryData = null;
		
		if ($isRemoteRequest) {
			$curlHandle = $this->_getCurlHandle($src);
			$binaryData = curl_exec($curlHandle);
			
			if ($binaryData === false) {
				$curlErrorCode = curl_errno($curlHandle);
				$curlErrorMessage = curl_error($curlHandle);
				curl_close($curlHandle);
				throw new SmushitApiException("An error occured while getting cURL response. cURL-Code: {$curlErrorCode} cURL-Message: {$curlErrorMessage}");
			}
			
			curl_close($curlHandle);
		} else {
			$binaryData = file_get_contents($src);
		}
		
		return $binaryData;
	}
	
	
	/**
	 * @param string $url
	 * @param string $method
	 * @param array $postData
	 * @throws SmushitApiException
	 * @return ressource A cURL handle
	 */
	private function _getCurlHandle($url, $method = self::CURL_METHOD_GET, array $postData = array()) {
		$curlHandle = curl_init($url);
		$curlOptions = array(
			CURLOPT_AUTOREFERER => false,
			CURLOPT_CRLF => false,
			CURLOPT_BINARYTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_FORBID_REUSE => true,
			CURLOPT_FRESH_CONNECT => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_TIMEOUT => 600,
			CURLOPT_ENCODING => '',
			CURLOPT_COOKIEFILE => self::$_cookieFile,
			CURLOPT_COOKIEJAR => self::$_cookieFile,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:10.0) Gecko/20100101 Firefox/10.0 FirePHP/0.6',
		);
		
		switch ($method) {
			case self::CURL_METHOD_HEAD:
				$curlOptions[CURLOPT_NOBODY] = true;
				break;
			case self::CURL_METHOD_POST:
				$curlOptions[CURLOPT_POST] = true;
				$curlOptions[CURLOPT_POSTFIELDS] = $postData;
				break;
			default:
				$curlOptions[CURLOPT_HTTPGET] = true;
				break;
		}
		
		if ($curlHandle === false) {
			throw new SmushitApiException('Can not init cURL.');
		}
		
		if (!curl_setopt_array($curlHandle, $curlOptions)) {
			curl_close($curlHandle);
			throw new SmushitApiException('Can not set cURL options.');
		}
		
		return $curlHandle;
	}
	
	
	/**
	 * @param string $src
	 * @param bool $isRemoteRequest
	 * @return bool true if ressource is available, false otherwise
	 */
	private function _isAvailable($src, $isRemoteRequest) {
		$available = false;
		
		if ($isRemoteRequest) {
			$curlHandle = $this->_getCurlHandle($src, self::CURL_METHOD_HEAD, array());
			curl_exec($curlHandle);
			$available = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE) == 200;
			curl_close($curlHandle);
		} else {
			$available = is_file($src);
		}
		
		return $available;
	}
	
	
	/**
	 * @param string $requestType
	 * @param bool $createZip
	 * @param string $task
	 * @param string $subTask
	 * @throws SmushitApiException
	 * @return bool|ressource true if API call was successful, false otherwise or a ZIP ressource
	 */
	private function _requestApi($requestType, $createZip = false, $task = null, $subTask = null) {
		if ($requestType == self::REQUEST_TYPE_OPTIMIZE) {
			$curlHandle = $this->_getCurlHandle(self::API_URL);
			$response = curl_exec($curlHandle);
			
			if ($response === false) {
				$curlErrorCode = curl_errno($curlHandle);
				$curlErrorMessage = curl_error($curlHandle);
				curl_close($curlHandle);
				throw new SmushitApiException("An error occured while getting cURL response. cURL-Code: {$curlErrorCode} cURL-Message: {$curlErrorMessage}");
			}
			
			curl_close($curlHandle);
			$matches = array();
			
			if (!preg_match('/smush.smusher_subtask = "(.*)";/', $response, $matches) || empty($matches[1])) {
				throw new SmushitApiException('Can not find subtask information in source');
			}
			
			$this->_subTask = trim($matches[1]);
			$this->_apiRequested = true;
			$localFiles = $remoteFiles = array();
			
			/* @var $SmushitImage SmushitImage */
			foreach ($this->_images as $SmushitImage) {
				if ($SmushitImage->isRemoteImage() && $this->_isAvailable($SmushitImage->getSrc(), $SmushitImage->isRemoteImage())) {
					$remoteFiles[] = $SmushitImage;
					$this->_requestBinaryData &&$SmushitImage->setSrcBinaryData($this->_getBinaryData($SmushitImage->getSrc(), $SmushitImage->isRemoteImage()));
				} elseif (!$SmushitImage->isRemoteImage() && $this->_isAvailable($SmushitImage->getSrc(), $SmushitImage->isRemoteImage())) {
					$localFiles[] = $SmushitImage;
					$this->_requestBinaryData && $SmushitImage->setSrcBinaryData($this->_getBinaryData($SmushitImage->getSrc(), $SmushitImage->isRemoteImage()));
				} else {
					$SmushitImage->setError('Could not get the src image');
				}
			}
			
			if ($remoteFiles) {
				/* @var $SmushitImage SmushitImage */
				foreach ($remoteFiles as $SmushitImage) {
					$query = http_build_query(array('img' => $SmushitImage->getSrc(), 'id' => $SmushitImage->getId(), 'task' => $this->getTask()), '', '&');
					$curlHandle = $this->_getCurlHandle(self::API_URL.'ws.php?'.$query);
					$response = curl_exec($curlHandle);
					
					if ($response === false) {
						$curlErrorCode = curl_errno($curlHandle);
						$curlErrorMessage = curl_error($curlHandle);
						curl_close($curlHandle);
						$SmushitImage->setError("An error occured while getting cURL response. cURL-Code: {$curlErrorCode} cURL-Message: {$curlErrorMessage}");
						continue;
					}
					
					curl_close($curlHandle);
					$response = json_decode($response, true);
					
					if (isset($response['error'])) {
						$SmushitImage->setError($response['error']);
						continue;
					}
					
					$SmushitImage->setDst(rawurldecode($response['dest']));
					$this->_requestBinaryData && $SmushitImage->setDstBinaryData($this->_getBinaryData($SmushitImage->getDst(), true));
					$SmushitImage->setDstSize($response['dest_size']);
					$SmushitImage->setPercent($response['percent']);
					$SmushitImage->setSrcSize($response['src_size']);
				}
			}
			
			if ($localFiles) {
				/* @var $SmushitImage SmushitImage */
				foreach ($localFiles as $SmushitImage) {
					$query = http_build_query(array('id' => $SmushitImage->getId(), 'task' => $this->getTask()), '', '&');
					$curlHandle = $this->_getCurlHandle(self::API_URL.'ws.php?'.$query, self::CURL_METHOD_POST, array('files[]' => "@{$SmushitImage->getSrc()}"));
					$response = curl_exec($curlHandle);
					
					if ($response === false) {
						$curlErrorCode = curl_errno($curlHandle);
						$curlErrorMessage = curl_error($curlHandle);
						curl_close($curlHandle);
						$SmushitImage->setError("An error occured while getting cURL response. cURL-Code: {$curlErrorCode} cURL-Message: {$curlErrorMessage}");
						continue;
					}
					
					curl_close($curlHandle);
					$response = json_decode($response, true);
					
					if (isset($response['error'])) {
						$SmushitImage->setError($response['error']);
						continue;
					}
					
					$SmushitImage->setDst(rawurldecode($response['dest']));
					$this->_requestBinaryData && $SmushitImage->setDstBinaryData($this->_getBinaryData($SmushitImage->getDst(), true));
					$SmushitImage->setDstSize($response['dest_size']);
					$SmushitImage->setPercent($response['percent']);
					$SmushitImage->setSrcSize($response['src_size']);
				}
			}
			
			if ($createZip) {
				$zip = $this->getZip($this->getTask(), $this->getSubTask());
				file_put_contents(self::$_cachePath.$this->getTask().'.zip', $zip);
			}
			
			return true;
		} elseif ($requestType == self::REQUEST_TYPE_ZIP) {
			if (is_file(self::$_cachePath.$task.'.zip')) {
				return file_get_contents(self::$_cachePath.$task.'.zip');
			}
			
			if (($task && !$subTask) || (!$task && $subTask)) {
				throw new SmushitApiException('You must not mix task and subtask. Either provide both or nothing.');
			}
			
			$files = array();
			$i = 0;
			
			foreach ($this->getImages() as $SmushitImage) {
				if (!$SmushitImage->hasError()) {
					$files["list[{$i}]"] = $SmushitImage->getDst();
					$i++;
				}
			}
			
			$query = http_build_query(array('task' => ($task ? $task : $this->getTask()).'-'.($subTask ? $subTask : $this->getSubTask())), '', '&');
			$curlHandle = $this->_getCurlHandle(self::API_URL.'zip.php?'.$query, self::CURL_METHOD_POST, $files);
			$response = curl_exec($curlHandle);
			
			if ($response === false) {
				$curlErrorCode = curl_errno($curlHandle);
				$curlErrorMessage = curl_error($curlHandle);
				curl_close($curlHandle);
				throw new SmushitApiException("An error occured while getting cURL response. cURL-Code: {$curlErrorCode} cURL-Message: {$curlErrorMessage}");
			}
			
//			file_put_contents(self::$_cachePath.'smushit_debug.log', $response, FILE_APPEND);
			curl_close($curlHandle);
			$response = json_decode($response, true);
					
			if (!isset($response['url'])) {
				throw new SmushitApiException('Smush it did not provide an URL to the ZIP file.');
			}
			
			$zip = $this->_getBinaryData($response['url'], true);
			file_put_contents(self::$_cachePath.($task ? $task : $this->getTask()).'.zip', $zip);
			return $zip;
		}
		
		throw new SmushitApiException('Unknown request type: '.$requestType);
	}
}

class SmushitApiException extends Exception {}

class SmushitImage {
	
	/**
	 * Destination URL of the smushed image.
	 * @var string
	 */
	private $_dst = null;
	
	/**
	 * Binary data of the dst image.
	 * @var binary
	 */
	private $_dstBinaryData = null;
	
	/**
	 * Mime Type of the dst image.
	 * @var string
	 */
	private $_dstMimeType = null;
	
	/**
	 * Destination size in bytes of the smushed image.
	 * @var int
	 */
	private $_dstSize = null;
	
	/**
	 * Error message of the API, if any.
	 * @var sring
	 */
	private $_error = null;
	
	/**
	 * ID of the image.
	 * @var string
	 */
	private $_id = null;
	
	/**
	 * Determines if the src is an URL or a local file.
	 * @var bool
	 */
	private $_isRemoteImage = null;
	
	/**
	 * Savings in percent of the smushed image.
	 * @var float
	 */
	private $_percent = null;
	
	/**
	 * Source URL of the smushed image.
	 * @var string
	 */
	private $_src = null;
	
	/**
	 * Binary data of the src image.
	 * @var binary
	 */
	private $_srcBinaryData = null;
	
	/**
	 * Mime Type of the src image.
	 * @var string
	 */
	private $_srcMimeType = null;
	
	/**
	 * Source size in bytes of the smushed image.
	 * @var int
	 */
	private $_srcSize = null;
	
	/**
	 * Task identifier for grouping smushed images.
	 * @var string
	 */
	private $_task = null;
	
	
	/**
	 * @param string $scr Source URL of the image
	 * @param string $task Task identifier for grouping images
	 */
	public function __construct($src, $task = null) {
		$this->_id = uniqid();
		$this->_isRemoteImage = strpos($src, 'http') === 0;
		$this->_src = (string)$src;
		$this->_task = (string)$task;
	}
	
	
	/**
	 * @return string Destination URL of the smushed image
	 */
	public function getDst() {
		return (string)$this->_dst;
	}
	
	
	/**
	 * @param string $dst Destination URL of the smushed image
	 */
	public function setDst($dst) {
		$this->_dst = (string)$dst;
	}
	
	
	/**
	 * @return binary Binary data of the dst image
	 */
	public function getDstBinaryData() {
		return (string)$this->_dstBinaryData;
	}
	
	
	/**
	 * @param binary $dstBinaryData Binary data of the dst image
	 */
	public function setDstBinaryData($dstBinaryData) {
		$this->_dstBinaryData = (string)$dstBinaryData;
	}
	
	
	public function getDstMimeType() {
		if ($this->_dstMimeType) {
			return $this->_dstMimeType;
		}
		
		$binary = $this->getDstBinaryData();
		
		if ($binary) {
			$finfo = finfo_open(FILEINFO_MIME);
			list($this->_dstMimeType,) = explode(';', finfo_buffer($finfo, $binary));
			finfo_close($finfo);
		}
		
		return $this->_dstMimeType;
	}
	
	
	/**
	 * @return int Destination size in bytes of the smushed image
	 */
	public function getDstSize() {
		return (int)$this->_dstSize;
	}
	
	
	/**
	 * @param int $dstSize Destination size in bytes of the smushed image
	 */
	public function setDstSize($dstSize) {
		$this->_dstSize = (int)$dstSize;
	}
	
	
	/**
	 * @return string Error message of the API, if any
	 */
	public function getError() {
		return (string)$this->_error;
	}
	
	
	public function hasError() {
		return !empty($this->_error);
	}
	
	
	/**
	 * @param string $error Error message of the API
	 */
	public function setError($error) {
		$this->_error = (string)$error;
	}
	
	
	/**
	 * @return string ID of the image
	 */
	public function getId() {
		return (string)$this->_id;
	}
	
	
	/**
	 * @return bool Determines if the src is an URL or a local file
	 */
	public function isRemoteImage() {
		return (bool)$this->_isRemoteImage;
	}
	
	
	/**
	 * @return float Savings in percent of the smushed image
	 */
	public function getPercent() {
		return (float)$this->_percent;
	}
	
	
	/**
	 * @param float $savings Savings in percent of the smushed image
	 */
	public function setPercent($percent) {
		$this->_percent = (float)$percent;
	}
	
	
	/**
	 * @return float Savings of the smushed image
	 */
	public function getSavings() {
		return (float)($this->getSrcSize()-$this->getDstSize());
	}
	
	
	/**
	 * @return string Source URL of the smushed image
	 */
	public function getSrc() {
		return (string)$this->_src;
	}
	
	
	/**
	 * @return binary Binary data of the src image
	 */
	public function getSrcBinaryData() {
		return (string)$this->_srcBinaryData;
	}
	
	
	/**
	 * @param binary $srcBinaryData Binary data of the src image
	 */
	public function setSrcBinaryData($srcBinaryData) {
		$this->_srcBinaryData = (string)$srcBinaryData;
	}
	
	
	public function getSrcMimeType() {
		if ($this->_srcMimeType) {
			return $this->_srcMimeType;
		}
		
		$binary = $this->getSrcBinaryData();
		
		if ($binary) {
			$finfo = finfo_open(FILEINFO_MIME);
			list($this->_srcMimeType,) = explode(';', finfo_buffer($finfo, $binary));
			finfo_close($finfo);
		}
		
		return $this->_srcMimeType;
	}
	
	
	/**
	 * @return int Source size in bytes of the smushed image
	 */
	public function getSrcSize() {
		return (int)$this->_srcSize;
	}
	
	
	/**
	 * @param int $srcSize Source size in bytes of the smushed image
	 */
	public function setSrcSize($srcSize) {
		$this->_srcSize = (int)$srcSize;
	}
	
	
	/**
	 * @return string Task identifier for grouping smushed images
	 */
	public function getTask() {
		return (string)$this->_task;
	}
}
?>