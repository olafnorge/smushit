# Smush.it™ PHP-API 

The SmushitApi is a nice tool to pass your images to Smush.it™.
It supports posting local files and passing remote files like implemeted on the Smush.it™ website.
It also provides the ability to download the optimized images as a ZIP file directly from Smush.it™.
This is the most benefit from all the other APIs at github.com.

* Fast
* No configuration needed.

## Usage

### Learn or train documents:

	try {
		/* @var $SmushitApi SmushitApi */
		$SmushitApi = new SmushitApi();
					
		if (!$SmushitApi->optimize($files) || !$SmushitApi->getImageCount()) {
			throw new Exception('SmushitApi returned errornous.');
		}
					
		/* @var $SmushitImage SmushitImage */
		foreach ($SmushitApi->getImages() as $SmushitImage) {
			if (!$SmushitImage->hasError()) {
				$binaryData = $SmushitImage->getDstBinaryData();
				// do something with the optimized image
			}
		}
	} catch (Exception $Exception) {
		trigger_error("Can not smush image. Message: {$Exception->getMessage()}\nStack: {$Exception->getTraceAsString()}", E_USER_WARNING);
	}

