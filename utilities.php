<?php
/**
 * Created by PhpStorm.
 * User: messi
 * Date: 12/01/18
 * Time: 14.39
 */

namespace AppBundle\Utilities;

use Aws\S3\S3Client as S3Client;
use Aws\Credentials\CredentialProvider;


class Utilities
{
    public static function checkFile($file, $validFormats=array('image', 'pdf'),  $size=5242880)
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            // nel caso la dimensione del file superi upload_max_filesize (php.ini) entra qua con error code 1
            throw new \Exception("Upload failed with error code " . $file->getError());
        }
        // controllo sulle dimensioni del file
        if (filesize($file) > $size ) {
            $filesize = filesize($file);
            throw new \Exception("File too big! ($filesize)");
        }
        // controllo sul tipo di file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file->getPathname());

        // verifico sia un file CSV
        $mimes = array('application/vnd.ms-excel','text/plain','text/csv','text/tsv');
        if(in_array($mime, $mimes)){
            if (in_array("csv", $validFormats)) {
                return;
            }
        }

        // verifico sia un file PDF
        switch ($mime) {
            case 'application/pdf':
                if (in_array("pdf", $validFormats)) {
                    return;
                }
        }

        // verifico sia un file con estensione gif, jpeg o png
        $info = getimagesize($file);
        if ($info !== FALSE) {
            if (($info[2] === IMAGETYPE_GIF) || ($info[2] !== IMAGETYPE_JPEG) || ($info[2] !== IMAGETYPE_PNG)) {
                if (in_array("image", $validFormats)) {
                    return;
                }
            }
        }

        throw new \Exception("Not valid type file!");
    }

    public static function uploadOnS3($file, $key, $acl){
        $s3 = self::S3Login();
        $bucket = getenv('S3_BUCKET');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file->getPathname());
        $result = $s3->putObject(array(
            'Bucket' => $bucket,
            'Key' => $key,
            'ContentType' => $mime,
            'SourceFile' => $file->getPathName(),
            'ACL' => $acl,
        ));
        return $result;
    }

    public static function downloadFromS3($key){
        $s3 = self::S3Login();
        $bucket = getenv('S3_BUCKET');
        $result = $s3->getObject(array(
            'Bucket' => $bucket,
            'Key' => $key,
        ));
        return $result;
    }

    public static function deleteByUrlFromS3($url)
    {
        $key = substr($url ,
            strpos(
                $url,
                getenv('S3_BUCKET')
            )
            + strlen(getenv('S3_BUCKET'))
            + 1
        );
        return self::deleteByKeyFromS3($key);
    }

    public static function deleteByKeyFromS3($key)
    {
        $s3 = self::S3Login();
        $bucket = getenv('S3_BUCKET');
        $result = $s3->deleteObject(array(
            'Bucket' => $bucket,
            'Key' => $key,
        ));
        return $result;
    }

    public static function deleteByPrefixFromS3($prefix)
    {
        $s3 = self::S3Login();
        $bucket = getenv('S3_BUCKET');
        $objects = $s3->getIterator('ListObjects', array(
            "Bucket" => $bucket,
            "Prefix" => $prefix
        ));
        foreach($objects as $object){
            self::deleteByKeyFromS3($object['Key']);
        }
    }

    private static function S3Login(){
        $s3 = new S3Client(array(
            'version' => 'latest',
            'region' => 'eu-west-1',
            'credentials' => CredentialProvider::env()
        ));
        return $s3;
    }
}