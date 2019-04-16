<?php

if(isset($argv[1])) {
  $paramPathFolder = $argv[1];
}
if(isset($argv[2])) {
  $paramPathFileTimeMod = $argv[2];
}
if(isset($argv[3])) {
  $paramPathFileHashChecksum  = $argv[3];
}

if (!isset($paramPathFolder)) {
  $paramPathFolder = '';
  while($paramPathFolder == '') {
    $paramPathFolder = readline('You don\'t provide folder path as a first parameter. Please provide a path: ');
  }
}

if (!isset($paramPathFileTimeMod)) {
  $paramPathFileTimeMod = '';
  while($paramPathFileTimeMod == '') {
    $paramPathFileTimeMod = readline('You don\'t provide path to time modification file as a second parameter. Please provide a path: ');
  }
}

if (!isset($paramPathFileHashChecksum)) {
  $paramPathFileHashChecksum = '';
  while($paramPathFileHashChecksum == '') {
    $paramPathFileHashChecksum = readline('You don\'t provide path to hash checksum file as a third parameter. Please provide a path: ');
  }
}

$patternReg = '/[^\/]+[\/]?$/';
preg_match($patternReg, $paramPathFolder, $matches);
if (substr($paramPathFolder, -1) != '/') {
    $paramPathFolder .= '/';
}

if(!file_exists($paramPathFileTimeMod)) {
	file_put_contents($paramPathFileTimeMod, '');
}
$data = file_get_contents($paramPathFileTimeMod);
$data = unserialize($data);
if(!is_array($data)) {
	$data = array();
}
getTimeModifiaction($paramPathFolder, $paramPathFileTimeMod, $paramPathFileHashChecksum);
$data = serialize($data);
file_put_contents($paramPathFileTimeMod, $data);

function setmd5hash($pathParameter, $path) {
	if(!file_exists($path)) {
		file_put_contents($path, '');
	}
	$data = file_get_contents($path);
	$data = unserialize($data);
	if(!is_array($data)) {
		$data = array();
	}
	$md5hash = md5_file($pathParameter);
	$data[$pathParameter] = $md5hash;
	$data = serialize($data);
	file_put_contents($path, $data);
}

function getTimeModifiaction($paramPathFolder, $paramPathFileTimeMod, $paramPathFileHashChecksum) {
	global $data;
	$fileList = array_diff(scandir($paramPathFolder), array('..','.'));
	foreach($fileList as $file) {
		$fullPath = $paramPathFolder . $file;
		if(is_dir($fullPath)) {
			$fullPath .= '/';
			getTimeModifiaction($fullPath, $paramPathFileTimeMod, $paramPathFileHashChecksum);
		} else {
			$modifiacationTime = date("YmdHis", filemtime($fullPath));
			if(array_key_exists($fullPath, $data)) {
				$modifiacationTime_previous = $data[$fullPath];
				if($modifiacationTime > $modifiacationTime_previous) {
					$data[$fullPath] = 	$modifiacationTime;
					setmd5hash($fullPath, $paramPathFileHashChecksum);
				}
			} else {
				$data[$fullPath] = 	$modifiacationTime;
				setmd5hash($fullPath, $paramPathFileHashChecksum);
			}
		}
	}

}

?>
