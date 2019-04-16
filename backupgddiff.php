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
  $fileHashData = $argv[3];
}
if(isset($argv[4])) {
  $email = $argv[4];
}

if(!isset($pathParameter)) {
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
if(!isset($fileHashData)) {
  $fileHashData = '';
  while($fileHashData == '') {
    $fileHashData = readline('You don\'t provide path to hash file as a second parameter. Please provide a path: ');
  }
}

$patternReg = '/[^\/]+[\/]?$/';
preg_match($patternReg, $pathParameter, $matches);
if (substr($pathParameter, -1) != '/') {
    $pathParameter .= '/';
}
$folderNameToUpload = rtrim($matches[0], "/");
$mainBackupFolder = '_' . $folderNameToUpload;

//$mainPathInfoHashData = pathinfo($fileHashData);
//$parentPathHashData = $mainPathInfoHashData['dirname'] . '/';
//$fileHashData = $parentPathHashData . $folderNameToUpload . '_hash_checksums.txt';

if(isset($email) && (strpos($email, '@') !== false)) {
  mail($email, 'Starts backup diff  ' . $folderNameToUpload, 'Details:' . "\n" . 'Backup folder: ' . $folderNameToUpload . "\n" . 'Start time: ' . date('l jS F Y h:i:s A'));
}

$service = new Google_Service_Drive($client);
$nextPageToken = null;
$arrayResult = array();
$regExp = '/backup_(\d+)' . $mainBackupFolder . '/';
do {
	$options = array('q' => "name contains 'backup_' and '" . $googleDriveFolderID .  "' in parents and mimeType = 'application/vnd.google-apps.folder'", 'fields' => 'nextPageToken, files(name)', 'pageToken' => $nextPageToken);
	$allFiles = $service->files->listFiles($options);
	$nextPageToken = $allFiles->nextPageToken;
	foreach($allFiles->files as $file) {
		if(preg_match($regExp, $file->name)) {
			array_push($arrayResult, $file->name);
		}
	}
} while ($nextPageToken != null);
rsort($arrayResult);
$backupName = $arrayResult[0];
$options = array('q' => "name = '" . $backupName . "'");
$backupDetailsFile = $service->files->listFiles($options)->files;
$backupDetails = $backupDetailsFile[0];
$backupID = $backupDetails->id;
$options = array('q' => "'" . $backupID . "' in parents");
$mainFolderBackupData = $service->files->listFiles($options)->files;
$mainFolderBackup = $mainFolderBackupData[0];
$mainFolderBackupID = $mainFolderBackup->id;
scanDirectory($pathParameter, $mainFolderBackupID);
if(isset($email) && (strpos($email, '@') !== false)) {
  mail($email, 'Complete backup diff: ' . $folderNameToUpload, 'Details:' . "\n" . 'Folder\'s name: ' . $folderNameToUpload . "\n" . 'End time: ' . date('l jS F Y h:i:s A'));
}

function getFilesFromFolder($folderID) {
	global $service;
	$nextPageToken = null;
	$fileList = array();
	do {
		$options = array(
			'q' => "'" . $folderID . "' in parents",
			'fields' => 'nextPageToken, files(id, name, md5Checksum, trashed)',
			'pageToken' => $nextPageToken
			);
		$allFiles = $service->files->listFiles($options);
		$nextPageToken = $allFiles->nextPageToken;
		foreach($allFiles->files as $file) {
			array_push($fileList, $file);
		}
	} while ($nextPageToken != null);
	return $fileList;
}

function scanDirectory($directoryLocalPath, $googleDriveFolderID) {
	global $service, $fileHashData;
	$data = file_get_contents($fileHashData);
	$data = unserialize($data);
	$fileListLocal = array_diff(scandir($directoryLocalPath), array('..','.'));
	$fileListInGoogleDrive = getFilesFromFolder($googleDriveFolderID);
	foreach($fileListLocal as $singleFileLocal) {
		$element = $directoryLocalPath . $singleFileLocal;
		$isFound = false;
		if(!is_dir($element)) {
			foreach ($fileListInGoogleDrive as $singleFileInGoogleDrive) {
				$googleDriveFileName = $singleFileInGoogleDrive->name;
				$googleDriveFileChecksum = $singleFileInGoogleDrive->md5Checksum;
				$googleDriveFileID = $singleFileInGoogleDrive->id;
				$inTrash = $singleFileInGoogleDrive->trashed;
				if (($singleFileLocal == $googleDriveFileName) && $googleDriveFileChecksum && !$inTrash) {
					$isFound = true;
					$localChecksum = $data[$element];
					if ($localChecksum != $googleDriveFileChecksum) {
						$service->files->delete($googleDriveFileID);
						uploadLargeFile($element, $singleFileLocal, $googleDriveFolderID);
					}
					break;
				}
			}
			if(!$isFound) {
				uploadLargeFile($element, $singleFileLocal, $googleDriveFolderID);
				$isFound = false;
			}
		} else {
			foreach ($fileListInGoogleDrive as $singleFileInGoogleDrive) {
				$googleDriveFileName = $singleFileInGoogleDrive->name;
				$googleDriveFileChecksum = $singleFileInGoogleDrive->md5Checksum;
				$googleDriveFileID = $singleFileInGoogleDrive->id;
				$inTrash = $singleFileInGoogleDrive->trashed;
				if (($singleFileLocal == $googleDriveFileName) && !$googleDriveFileChecksum && $googleDriveFileID && !$inTrash) {
					$isFound = true;
					scanDirectory($element . '/', $googleDriveFileID);
					break;
				}
			}
			if(!$isFound) {
				backupToGoogleDrive($element . '/', $singleFileLocal, $googleDriveFolderID);
				$isFound = false;
			}
		}
	}

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
        	$idpliku;
        	do {
        		$isUploaded = uploadLargeFile($element, $singleFile, $folderID);
        		if(!$isUploaded) {
        			file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $element . ' failure!' . "\n", FILE_APPEND );
        			print(date('l jS F Y h:i:s A') . ' ' . $element . ' failure!' . "\n" );
        		}
        	} while (!$isUploaded);
        	if($isUploaded === 'empty') {
        		file_put_contents('backuplog.txt', date('l jS F Y h:i:s A') . ' ' . $element . ' file is empty, skipping file!' . "\n", FILE_APPEND );
        		print(date('l jS F Y h:i:s A') . ' ' . $element . ' file is empty, skipping file!' . "\n");
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
			$chunk = fread($handle, (int) $chunkSizeBytes);
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
