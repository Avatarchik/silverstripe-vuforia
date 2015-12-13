<?php

/**
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class VuforiaImage extends Image {
	const COMPLETE_STATUS = 'complete';
	const PROCESS_STATUS = 'processing';
	const ERROR_STATUS = 'error';
	
	private static $db = array(
		'TargetWidth'		=> 'Double',
		'VuforiaData'		=> 'Text',
		'ProcessStatus'		=> 'Varchar(64)',
		'Messages'			=> 'Text',
	);
	
	/**
	 *
	 * @var VuforiaAPIService 
	 */
	public $vuforiaAPIService;
	
	/**
	 * Defined by the API
	 */
	public $maxFileSize = 2359296;
	
	public function getVuforiaInfo() {
		$data = $this->VuforiaData ? ArrayData::create(json_decode($this->VuforiaData, true)) : null;
		return $data;
	}
	
	public function getVuforiaRatingInfo() {
		if(($this->getVuforiaInfo()) && $data = $this->getVuforiaInfo()->target_record->tracking_rating){
			return $data;
		}
	}
	
	public function toBase64() {
		if (file_exists($this->getFullPath())) {
			$data = file_get_contents($this->getFullPath());
			return base64_encode($data);
		}
	}
	
	public function vuforiaName() {
		$name = trim(str_replace('/', '_', $this->getFilename()), '_');
		
		if (strpos($name, 'assets') === 0) {
			$name = substr($name, 6);
		}
		return trim($name, '_');
	}

	public function vuforiaMetadata() {
		$data = array(
			'ID'		=> $this->ID,
			'Link'		=> $this->Link(),
		);
		return $data;
	}
	
	public function dataForVuforia() {
		$data = array();
		$data['name'] = $this->vuforiaName();
		$data['width'] = (double) $this->TargetWidth;
		$data['active_flag'] = 1;
		$data['application_metadata'] = base64_encode(json_encode($this->vuforiaMetadata()));
		$data['image'] = $this->toBase64();
		return $data;
	}
	
	public function verifyAcceptable() {
		$okay = true;
		
		$messages = array();
		if (!file_exists($this->getFullPath())) {
			$okay = false;
			$messages[] = "Image file missing. ";
		}
		
		$size = $this->getAbsoluteSize(); 
		
		// defined by vuforia API 
		if ($size > $this->maxFileSize) {
			$okay = false;
			$messages[] = "Image size must be less than 2.25MB. ";
		}
		
		if (!$this->TargetWidth) {
			$okay = false;
			$messages[] = "Image must have a 'Target Width' specified. ";
		}
		
		if (!$okay && $this->ProcessStatus != VuforiaImage::ERROR_STATUS) {
			$this->ProcessStatus = VuforiaImage::ERROR_STATUS;
			$this->Messages = implode(' ', $messages);
			$this->write();
		}
		
		return $okay;
	}
	
	public function getCMSFields() {
		$fields = parent::getCMSFields();
		
		$fields->addFieldToTab('Root.Main', NumericField::create('TargetWidth', 'Target width'));
		$data = $this->getVuforiaInfo();
		
		
		if ($this->ProcessStatus != self::COMPLETE_STATUS) {
			$fields->addFieldToTab('Root.Main', ReadonlyField::create('VfStatus', 'Vuforia status',  $this->ProcessStatus));
			$fields->addFieldToTab('Root.Main', ReadonlyField::create('VfErrors', 'Vuforia messages',  $this->Messages));
		}
		
		$text = '';
		
		if (defined('JSON_PRETTY_PRINT')) {
			$text = str_replace(' ', '&nbsp;', Convert::raw2xml(json_encode(json_decode($this->VuforiaData), JSON_PRETTY_PRINT)));
		} else {
			$basic = str_replace('","', "\",\n\"", $this->VuforiaData);
			$text = str_replace(' ', '&nbsp;', nl2br(Convert::raw2xml($basic)));
		}
		
		
		$fields->addFieldToTab('Root.Main', LiteralField::create('VuforiaDataInfo', nl2br($text)));
		
		
		return $fields;
	}
	
	public function onBeforeDelete() {
		parent::onBeforeDelete();
		$this->vuforiaAPIService->deleteTarget($this);
	}
	
	private $updating = false;
	public function onBeforeWrite() {
		
		parent::onBeforeWrite();
		
		if($this->getExtension() == 'png' || $this->getExtension() == 'gif'){
			$this->convertToJPEG();
		}
		
		if (!$this->TargetWidth) {
			$this->TargetWidth = 200;
		}
		
		$existing = $this->getVuforiaInfo();
		
		if (!$this->updating) {
			$this->updating = true;
			
			if (!$existing && $this->TargetWidth) {
				$this->vuforiaAPIService->uploadNewTarget($this);
			}
			
			if ($existing && $this->isChanged('TargetWidth')) {
				// update the existing
				$this->vuforiaAPIService->updateTarget($this);
			}
			
		}
	}
	
	public function onAfterUpload() {
		parent::onAfterUpload();
		
		if (!$this->TargetWidth) {
			return;
		}
		$existing = $this->getVuforiaInfo();
		if ($existing) {
			// update the remote
			$this->vuforiaAPIService->updateTarget($this);
		} 
	}
	
	public function convertToJPEG() {
		
		/* start jpeg conversion */	
		$input_file = Director::absoluteBaseURL().$this->Filename;
		$timeStamp = time();

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
		{
			$output_file = $_SERVER['DOCUMENT_ROOT']."\assets\Uploads\marker_".$timeStamp.".jpeg";
		}
		else
		{
			$output_file = $_SERVER['DOCUMENT_ROOT']."/assets/Uploads/marker_".$timeStamp.".jpeg";
		}


        
        $type = exif_imagetype($input_file);
				
		/* do the conversion */
		$input = imagecreatefrompng($input_file);
		list($width, $height) = getimagesize($input_file);
		$output = imagecreatetruecolor($width, $height);
		$white = imagecolorallocate($output,  255, 255, 255);
		imagefilledrectangle($output, 0, 0, $width, $height, $white);
		imagecopy($output, $input, 0, 0, 0, 0, $width, $height);
		imagejpeg($output, $output_file, 100);
					
		/* get file size of new jpeg */
		$fileSize = filesize($output_file);
		
		/* update */
		$this->Filename = "assets/Uploads/marker_".$timeStamp.".jpeg";
        
	}
	
}
