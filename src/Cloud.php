<?php
namespace Iclicksee\FileSystem;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

/**
 * Cloud component
 */
class Cloud {

    /**
     * Wrapper for connection to Flysystem object
     * @var
     */

     /**
     * Constructor.
     */
    public function __construct(array $config)
    {
        $this->bucket = empty($config['S3_BUCKET']) ? getenv('S3_BUCKET') : $config['S3_BUCKET'];
        $this->region = empty($config['S3_REGION']) ? getenv('S3_REGION') : $config['S3_REGION'];
        $client = S3Client::factory([
            'credentials' => [
                'key'    => empty($config['AWS_ACCESS_KEY_ID']) ? getenv('AWS_ACCESS_KEY_ID') : $config['AWS_ACCESS_KEY_ID'],
                'secret' => empty($config['AWS_SECRET_ACCESS_KEY']) ? getenv('AWS_SECRET_ACCESS_KEY') : $config['AWS_SECRET_ACCESS_KEY'],
            ],
            'region' => $this->region,
            'version' => 'latest',
        ]);
        
        $adapter = new AwsS3Adapter(
            $client, 
            $this->bucket, 
            empty($config['prefix']) ? 'public' : $config['prefix']
        );

        $this->Filesystem = new Filesystem($adapter);
    }

    /**
     * Get the file info
     * @param string $path
     * @return bool|string
     */
    public function info(string $path) {
        $response['timestamps'] = $this->Filesystem->getTimestamp($path);
        $response['mimetype'] = $this->Filesystem->getMimeType($path);
        return $response;
    }
    
    /**
     * Get the file contents from $path where $path is from root of bucket
     * @param $path
     * @param bool $returnStream
     * @return bool|string
     */
    public function get($path, $returnStream = false) {
        if ($path) {
            $func = $returnStream ? 'readStream' : 'read';
            return $this->Filesystem->$func($path);
        }

        return false;
    }

    /**
     * Upload a file to the remote location $remotePath (path is from root of location/bucket) and set permissions
     * Once pushed, add localfile to tmpFiles[] for deletion at end of routine
     *
     * @param string $localPath
     * @param string $remotePath
     * @param string $visibility (public|private)
     * @param array $metadata
     * @return mixed
     */
    public function put($localPath = '', $remotePath = '', $visibility = 'public', $metadata = null, $addTotmpFiles = true) {

        if (file_exists($localPath)) {
            $stream = fopen($localPath, 'r');
            $config = [
                'visibility' => $visibility,
                'Content-Disposition' => 'attachment'
            ];

            if ($metadata) {
                $config['Metadata'] = $metadata;
            }

            if ($this->Filesystem->writeStream($remotePath, $stream, $config)) {

                if ($addTotmpFiles) {
                    $this->tmpFiles[] = $localPath;
                }
                return $this->url($remotePath);
            }
        }

        return false;
    }

    /**
     * Delete an object from the bucket
     * @param $path
     * @return bool
     */
    public function delete($path) {

        if ($this->Filesystem->has($path)) {
            return (bool)$this->Filesystem->delete($path);
        }

        return false;
    }

    /**
     * Get the url of an object in S3 at $path. Path is from root of bucket
     * @param string $path
     * @return bool|string
     */
    public function url($path = '') {

        if ($path) {
            return $this->_getObjectUrl($path);
        }

        return false;
    }

    /**
     * Does a remote file exist?
     *
     * @param  string $path S3path
     * @return bool
     */
    public function check($path = '') {

        return (bool)$this->Filesystem->has($path);

    }

    /**
     * Upload a file to the remote location $remotePath (path is from root of location/bucket) and set permissions
     * If file already exists in the remote location, delete it first
     * Once pushed, add localfile to tmpFiles[] for deletion at end of routine
     *
     * @param string $localPath
     * @param string $remotePath
     * @param string $visibility (public|private)
     * @return mixed
     */
    public function replace($localPath = '', $remotePath = '', $visibility = 'public', &$msg = [], $addTotmpFiles = true) {

        if (file_exists($localPath)) {

            //if remote path exists, delete it first
            if ($this->check($remotePath)) {
                $this->delete($remotePath);
                $msg[] = sprintf("Remote file already exists (%s) so replaced using (%s)", $remotePath, $localPath);
            }

            //push to S3
            return $this->put($localPath, $remotePath, $visibility, null, $addTotmpFiles);

        }

        return false;
    }


    /**
     * Wrapper method for fetching the full URL of the object in the cloud at $path
     * @param string $path
     * @return mixed
     */
    protected function _getObjectUrl($path = '') {

        return $this->Filesystem->getAdapter()->getClient()->getObjectUrl($this->bucketName, $path);
    }


    /**
     * Get time-expiried url of an object in S3 at $path. Path is from root of bucket
     *
     * (Read more: https://docs.aws.amazon.com/aws-sdk-php/v3/guide/service/s3-presigned-url.html)
     *
     * @param string $path
     * @return bool|string

   */
    public function presignedUrl($path = null, $timeperiod = '5 seconds', $download = true) {

        if (empty($path)) {
            return false;
        }

        $s3 = $this->Filesystem->getAdapter()->getClient();
        $options = [
            'Bucket' => $this->bucketName,
            'Key' => $path
        ];

        if ($download) {
            $options['ResponseContentDisposition'] = 'attachment; filename=' . pathinfo($path, PATHINFO_BASENAME);
            $options['ResponseCacheControl'] = 'No-cache';
        }

        $request = $s3->createPresignedRequest(
            $s3->getCommand('GetObject', $options),
            $timeperiod
        );

        $url = (string)$request->getUri();

        return $url;

    }

    /**
     * Wrapper method for fetching the full URL of the object in the cloud at $path
     * @param string $path
     * @return mixed
     */
    public function has($path = '') {
        return $this->Filesystem->has($path);
    }

}
