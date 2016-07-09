<?php

/**
 * 
 *
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class VuforiaAPIService {
	public $url						= "https://vws.vuforia.com";
	public $requestPath				= "/targets";
	private $request;
	/**
	 * Default access key
	 *
	 * @var string 
	 */
	public $accessKey;
	
	/**
	 * Default secret key
	 *
	 * @var string
	 */
	public $secretKey;
	
	/** 
	 * The target being uploaded into
	 *
	 * @var string 
	 */
	public $targetName;
	
	/**
	 *
	 * @var SignatureBuilder
	 */
	public $signatureBuilder;
	
	public function checkImageStatus(VuforiaImage $image) {
		if (!$image->verifyAcceptable()) {
			return $image;
		}
		$vuforiaData = $image->getVuforiaInfo();
		if (!$vuforiaData) {
			
		} else {
			// check the status. 
			$currentStatus = $image->ProcessStatus;
			if ($image->ProcessStatus != VuforiaImage::COMPLETE_STATUS) {
				$this->retrieveTargetStatus($image);
				
				if ($currentStatus != $image->ProcessStatus) {
					$image->write();
				}
			}
		}
		return $image;
	}
	
	function GetAllTargets(){
		$this->requestPath = $this->requestPath;
		
		$this->execGetAllTargets();
	}
	
	private function execGetAllTargets(){
		
		$this->request = new HTTP_Request2();
		$this->request->setMethod( HTTP_Request2::METHOD_GET );
		
		$this->request->setConfig(array(
				'ssl_verify_peer' => false
		));
		
		$this->request->setURL( $this->url . $this->requestPath );
		
		// Define the Date and Authentication headers
		$this->updateHeaders();
		
		
		try {
		
			$response = $this->request->send();
		
			if (200 == $response->getStatus()) {
				echo $response->getBody();
				error_log($response->getBody());
			} else {
				echo 'Unexpected HTTP status: ' . $response->getStatus() . ' ' .
						$response->getReasonPhrase(). ' ' . $response->getBody();
			}
		} catch (HTTP_Request2_Exception $e) {
			echo 'Error: ' . $e->getMessage();
		}
		
		
	}

	public function uploadNewTarget(VuforiaImage $image) {
		
		if (!$image->verifyAcceptable()) {	
			return;
		}

		$data = $image->dataForVuforia();
		$toSend = json_encode($data);

		// array( 'width'=>320.0 , 'name'=>$this->targetName , 'image'=>$this->getImageAsBase64() , 
		// 'application_metadata'=>base64_encode("Vuforia test metadata") , 'active_flag'=>1 ) );
		
		$request = $this->createClient(HTTP_Request2::METHOD_POST);
		$request->setBody($toSend);
		$request->setHeader("Content-Type", "application/json" );

		$this->updateHeaders($request);

		try {
			$response = $request->send();
			
			if ($response->getStatus() == 200  || $response->getStatus() == 201 ) {

				$image->VuforiaData = $response->getBody();
				$image->Messages = 'Image processing';
				$image->ProcessStatus = VuforiaImage::PROCESS_STATUS;
				
			} else {
				$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
				
				$res = $response->getBody();
				if ($res) {
					$res = json_decode($res, true);
					if (isset($res['result_code']) && $res['result_code'] == 'TargetNameExist') {
						$image->Messages = "A file with the name ${data['name']} exists, please remove it from Vuforia first";
					}
				} else {

					$image->Messages = 'Failed uploading to Vuforia: ' . $response->getStatus() . ' ' .
						$response->getReasonPhrase(). ' ' . $response->getBody();
				}
				
				
			}

		} catch (HTTP_Request2_Exception $e) {
			
			$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
			$image->Messages = 'Failed uploading to Vuforia: ' . $response->getStatus() . ' ' .
						$response->getReasonPhrase(). ' ' . $response->getBody();
		}

		return $image;
	}

	public function updateTarget(VuforiaImage $image) {
		
		$data = $image->getVuforiaInfo();
		if (!$data) {
			return;
		}
		
		if (!isset($data->target_record->target_id) && !isset($data->target_id)) {
			return;
		}

		// we're going to PUT the data at its URL
		try {
			$targetId = isset($data->target_record->target_id) ? $data->target_record->target_id : $data->target_id;
			$request = $this->createClient(HTTP_Request2::METHOD_PUT, $targetId);
			
			$data = $image->dataForVuforia();

			$request->setBody(json_encode($data));
			$request->setHeader("Content-Type", "application/json" );
			$this->updateHeaders($request);
			
			$response = $request->send();

			
			if (200 == $response->getStatus() || 201 == $response->getStatus() ) {
				$info = json_decode($response->getBody(), true);
				if ($info && isset($info['transaction_id'])) {
					$info['target_id'] = $targetId;
					$image->VuforiaData = json_encode($info);
				}
				$image->ProcessStatus = VuforiaImage::PROCESS_STATUS;
				$image->Messages = 'Image processing';
			} else {
				$image->VuforiaData = '';
				$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
				$image->Messages = 'Failed uploading to Vuforia: ' . $response->getStatus() . ' ' .
						$response->getReasonPhrase(). ' ' . $response->getBody();
			}
			
		} catch (Exception $ex) {

			$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
			$image->Messages = 'Failed uploading to Vuforia: ' . $response->getStatus() . ' ' .
						$response->getReasonPhrase(). ' ' . $response->getBody();
		}
	}

	public function retrieveTargetStatus(VuforiaImage $image) {
		$data = $image->getVuforiaInfo();
		// 
		if (!$data) {
			return;
		}
		if (!isset($data->target_record->target_id) && !isset($data->target_id)) {
			return;
		}

		try {
			
			$targetId = isset($data->target_record->target_id) ? $data->target_record->target_id : $data->target_id;
			$details = $this->getTargetMetadata($targetId);
			
			if ($details) {
				$image->VuforiaData = json_encode($details);
				$data = $image->getVuforiaInfo();
				if ($data->result_code == 'Success') {
					if ($data->status == 'processing') {
						$image->ProcessStatus = VuforiaImage::PROCESS_STATUS;
					} else {
						$image->ProcessStatus = VuforiaImage::COMPLETE_STATUS;
						$image->Messages = '';
					}
				} else {
					$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
					$image->Messages = $data->result_code;
				}
			} else {
				$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
				$image->Messages = 'Failed uploading to Vuforia';
			}
		} catch (Exception $e) {

			$image->ProcessStatus = VuforiaImage::ERROR_STATUS;
			$image->Messages = 'Failed uploading to Vuforia: ' . $e->getMessage();
		}

		return $image;
	}
	
	public function getTargetMetadata($targetId) {
		$request = $this->createClient(HTTP_Request2::METHOD_GET, $targetId);
		$this->updateHeaders($request);
		
		$response = $request->send();
		
		if (200 == $response->getStatus()) {
			return json_decode($response->getBody());
		}

		throw new Exception("Invalid response: " . $response->getReasonPhrase());
	}

	public function deleteTarget(VuforiaImage $image) {
		// if we've got no data, it doesn't exist remotely
		$data = $image->getVuforiaInfo();
		error_log("deleteTarget $data = $image->getVuforiaInfo(); = ");
		error_log(print_r($data));

		/*
		if (!$data || !$data->target_record || !$data->target_record->target_id) {
			return;
		}
		*/
		$request = $this->createClient(HTTP_Request2::METHOD_DELETE, $data->target_record->target_id);
		
		$this->updateHeaders($request);

		try {
		
			$response = $request->send();
			if (200 == $response->getStatus()) {
				error_log( $response->getBody());
				return true;
			} else {
				
			}
		} catch (HTTP_Request2_Exception $e) {
			error_log( 'Error: ' . $e->getMessage());
		}
		return false;


		$this->request = new HTTP_Request2();
		$this->request->setMethod( HTTP_Request2::METHOD_DELETE );
		
		$this->request->setConfig(array(
				'ssl_verify_peer' => false
		));
		$this->request->setURL( $this->url . $this->requestPath );
		// Define the Date and Authentication headers
		$this->setHeaders();
		try {
			$response = $this->request->send();
			if (200 == $response->getStatus()) {
				error_log( $response->getBody());
				return true;
			} else {
				error_log( 'Unexpected HTTP status: ' . $response->getStatus() . ' ' .
						$response->getReasonPhrase(). ' ' . $response->getBody());

			}
		} catch (HTTP_Request2_Exception $e) {
			echo 'Error: ' . $e->getMessage();
		}

	}
	
	/**
	 * 
	 * @param type $method
	 * @param string $url
	 * @return \HTTP_Request2
	 */
	protected function createClient($method = 'GET', $urlPart = null) {
		$url = $this->url . $this->requestPath; 
		if ($urlPart) {
			$url .= '/' . $urlPart;
		}
		$request = new HTTP_Request2();
		$request->setMethod($method);
		$request->setURL($url);
		$request->setConfig(array(
				'ssl_verify_peer' => false
		));
		
		return $request;
	}
	
	protected function updateHeaders($request) {
		// Define the Date and Authentication headers
		$date = new DateTime("now", new DateTimeZone("GMT"));
		// Define the Date field using the proper GMT format
		$request->setHeader('Date', $date->format("D, d M Y H:i:s") . " GMT" );
		
		// Generate the Auth field value by concatenating the public server access key w/ the private query signature for this request
		$request->setHeader("Authorization" , "VWS " . $this->accessKey . ":" . $this->signatureBuilder->tmsSignature($request, $this->secretKey));
	}
}
