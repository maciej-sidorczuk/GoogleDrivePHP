<?php

ini_set("memory_limit", -1);
set_time_limit(0);
ini_set('display_errors',1);
error_reporting(E_ALL);

require_once './api.php';

if(isset($argv[1])) {
  $pathParameter = $argv[1];
}
if(isset($argv[2])) {
  $googleDriveFolderID = $argv[2];
}
if(isset($argv[3])) {
  $email = $argv[3];
}

if (!isset($pathParameter)) {
  $pathParameter = '';
  while($pathParameter == '') {
    $pathParameter = readline('You don\'t provide folder path as a first parameter. Please provide a path: ');
  }
}
if (!isset($googleDriveFolderID)) {
  $googleDriveFolderID = '';
  while($googleDriveFolderID == '') {
    $googleDriveFolderID = readline('You don\'t provide folder id as a second parameter. Please provide a folder id: ');
  }
}

$patternReg = '/[^\/]+[\/]?$/';
preg_match($patternReg, $pathParameter, $matches);
if (substr($pathParameter, -1) != '/') {
    $pathParameter .= '/';
}
$folderNameToUpload = rtrim($matches[0], "/");
$path0 = $pathParameter;
$timestamp = date("Ymd");
$mainBackupFolder = 'backup_' . $timestamp . '_' . $folderNameToUpload;
if(isset($email) && (strpos($email, '@') !== false)) {
  mail($email, 'Start backup ' . $folderNameToUpload, 'Details:' . "\n" . 'Folder: ' . $folderNameToUpload . "\n" . 'Start time: ' . date('l jS F Y h:i:s A'));
}
$mainfolder = new Google_Service_Drive_DriveFile(array(
	'name' => $mainBackupFolder,
	'parents' => array($googleDriveFolderID),
	'mimeType' => 'application/vnd.google-apps.folder'));
if ($client->isAccessTokenExpired()) {
	$client = getClient();
	$service = new Google_Service_Drive($client);
}
$mainFolderReference = $service->files->create($mainfolder, array(
	'fields' => 'id'));
$mainfolderID = $mainFolderReference->id;
backupToGoogleDrive($path0, $folderNameToUpload, $mainfolderID);
if(isset($email) && (strpos($email, '@') !== false)) {
  mail($email, 'Complete backup: ' . $folderNameToUpload, 'Details:' . "\n" . 'Folder\'s name: ' . $folderNameToUpload . "\n" . 'End time: ' . date('l jS F Y h:i:s A'));
}

function backupToGoogleDrive( $path, $name, $id) {
    global $service, $client;
    $folderID;
    do {
    	$fileMetadata = new Google_Service_Drive_DriveFile(array(
            'name' => $name,
            'parents' => array($id),
            'mimeType' => 'application/vnd.google-apps.folder'));
        //in case previous file uploads too long that access key expired
        if($client->isAccessTokenExpired()) {
        	$client = getClient();
            $service = new Google_Service_Drive($client);
        }
        $file = $service->files->create($fileMetadata, array(
            'fields' => 'id'));
    	$folderID = $file->id;
        if(!isset($folderID)) {
        	file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $path . ' failure!' . "\n", FILE_APPEND);
        	print(date('l jS F Y h:i:s A') . ' ' . $path . ' failure!' . "\n");
        }
    } while (!isset($folderID));
    file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $path . ' success' . "\n", FILE_APPEND);
    print(date('l jS F Y h:i:s A') . ' ' . $path . ' success' . "\n");
    $fileList = array_diff(scandir($path), array('..','.'));
    foreach($fileList as $singleFile) {
        $element = $path . $singleFile;
        if(!is_dir($element)) {
        	$idOfFile;
        	do {
        		$isUploaded = uploadLargeFile($element, $singleFile, $folderID);
        		if(!$isUploaded) {
        			file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $element . ' failure!' . "\n", FILE_APPEND );
        			print(date('l jS F Y h:i:s A') . ' ' . $element . ' failure!' . "\n" );
        		}
        	} while (!$isUploaded);
        	if($isUploaded === 'empty') {
        		file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $element . ' empty file, skipping file!' . "\n", FILE_APPEND );
        		print(date('l jS F Y h:i:s A') . ' ' . $element . ' empty file, skipping file!' . "\n");
        	} else {
		    file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $element . ' success' . "\n", FILE_APPEND);
            print(date('l jS F Y h:i:s A') . ' ' . $element . ' success' . "\n");
		}
        } else {
            $element = $element . '/';
            backupToGoogleDrive($element, $singleFile, $folderID);
        }
    }
}

function uploadLargeFile($pathToFile, $nameOfUploadedFile, $uploadFolderId) {
	global $client, $service;
  //in case previous file uploads too long that access key expired
	if($client->isAccessTokenExpired()) {
		$client = getClient();
		$service = new Google_Service_Drive($client);
	}
	$handle = fopen($pathToFile, "rb");
	(float) $fileSize = (float) myFileSize($handle);
	//skipp files that size equals to 0
	if ($fileSize <= 0) {
		return 'empty';
	}
	$file = new Google_Service_Drive_DriveFile(array(
		'name' => $nameOfUploadedFile,
		'parents' => array($uploadFolderId),
		'mimeType' => '')
		);
	$file->title = $nameOfUploadedFile;
	$chunkSizeBytes = 1 * 1024 * 1024;
	// Call the API with the media upload, defer so it doesn't immediately return.
	$client->setDefer(true);
	$request = $service->files->create($file, array('fields' => 'id'));
	$media = new Google_Http_MediaFileUpload(
		$client,
		$request,
		'',
		null,
		true,
		$chunkSizeBytes
		);
	$status = false;
	$media->setFileSize($fileSize);
	$isError = false;
	while (!$status && !feof($handle)) {
		if (!$isError) {
			$chunk = fread($handle, $chunkSizeBytes);
		}
		$isError = false;
		try {
			$status = $media->nextChunk($chunk);
			print('Uploading file: ' . $nameOfUploadedFile . ' ' . (float) ($media->getProgress()/1024/1024) . ' MB / ' . ((float) $fileSize/1024/1024) . ' MB' . "\r");
			file_put_contents('backuplog.txt', 'Uploading file: ' . $nameOfUploadedFile . ' ' . (float) ($media->getProgress()/1024/1024) . ' MB / ' . ((float) $fileSize /1024/1024) . ' MB' . "\n", FILE_APPEND);
		} catch (Exception $e) {
			$isError = true;
			print("\n");
			print('Error occurs! I will retry in 15 seconds. Details: ' . $e->getMessage() . "\n");
			file_put_contents('backuplog.txt', 'Error occurs! Details: ' . $e->getMessage() . "\n", FILE_APPEND);
			sleep(15);
		}
	}

	// The final value of $status will be the data from the API for the object
	// that has been uploaded.
	$result = false;
	$isSuccess = false;
	if($status != false) {
		$result = $status;
		$isSuccess = true;
	}
	fclose($handle);
	// Reset to the client to execute requests immediately in the future.
	$client->setDefer(false);
	if ($isSuccess)
	{
		return true;
	} else {
		return false;
	}
}

function myFileSize($fp) {
    $return = false;
    if (is_resource($fp)) {
      if (PHP_INT_SIZE < 8) {
        // 32bit
        if (0 === fseek($fp, 0, SEEK_END)) {
          $return = 0.0;
          $step = 0x7FFFFFFF;
          while ($step > 0) {
            if (0 === fseek($fp, - $step, SEEK_CUR)) {
              $return += floatval($step);
            } else {
              $step >>= 1;
            }
          }
        }
      } elseif (0 === fseek($fp, 0, SEEK_END)) {
        // 64bit
        $return = ftell($fp);
      }
    }
    rewind($fp);
    return $return;
}

?>
