<?php

namespace LegalThings;

use InvalidArgumentException;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\CloudWatchLogs\CloudWatchLogsClient as Client;
use Aws\Result;

class CloudWatchClient
{
    /**
     * @var object
     */
    public $config;
    
    /**
     * @var Client
     */
    public $client;
    
    /**
     * @var string
     */
    protected $sequence_token;
    
    
    /**
     * Class constructor
     * 
     * @param object|array $config
     * @param Client       $client
     */
    public function __construct($config, $client = null)
    {
        $this->config = (object)$config;
        
        $this->client = $client ?: $this->create($this->config);
    }
    
    /**
     * Create a client
     * 
     * @param object $config
     * 
     * @return Logger $logger
     */
    protected function create($config)
    {
        $this->validateConfig($config);
        
        return new Client((array)$config->aws);
    }
    
    /**
     * Validate config
     * 
     * @param object $config
     */
    protected function validateConfig($config)
    {
        if (!isset($config->aws)) {
            throw new InvalidArgumentException("CloudWatchClient config 'aws' not given");
        }
    }
    
    
    /**
     * Log data in CloudWatch
     * Will automatically create missing groups and streams as needed
     * This is the recommended function to log data as it keeps track of sequence tokens
     * 
     * @param string|array|object $data
     * @param string              $group
     * @param string              $stream
     * @param array|object        $options ['retention_days' => 90]
     * 
     * @return Result
     */
    public function log($data, $group, $stream, $options = null)
	{
		return $this->logMultiple([$data], $group, $stream, $options);
    }

    /**
     * Log data in CloudWatch
     * Will automatically create missing groups and streams as needed
     * This is the recommended function to log data as it keeps track of sequence tokens
     * 
     * @param array               $data messages to log
     * @param string              $group
     * @param string              $stream
     * @param array|object        $options ['retention_days' => 90]
     * 
     * @return Result
	 */
	public function logMultiple(array $data, $group, $stream, $options = null)
	{
        if (!isset($this->sequence_token)) {
            $created = $this->createGroupAndStream($group, $stream, $options);
            if (isset($created['stream']) && isset($created['stream']['uploadSequenceToken'])) {
                $this->sequence_token = $created['stream']['uploadSequenceToken'];
            }
        }

		$events = [];	
		foreach ($data as $message)
		{
			if (is_array($message) || is_object($message)) {
	            // can only log strings to CloudWatch
    	        $message = json_encode((array)$message);
			}

			$events[] = ['message' => $message, 'timestamp' => time() * 1000];
		}
        
        try {
            $result = $this->putLogEvents($events, $group, $stream, $this->sequence_token);
        } catch (CloudWatchLogsException $e) {
            // something went wrong, invalidate sequence token so we can fetch the actual token from aws on subsequent calls
            $this->sequence_token = null;
            throw $e;
        }
        
        $this->sequence_token = $result->get('nextSequenceToken');
        
        return $result;
    }

    /**
     * Create group and stream if they didn't exist yet
     * 
     * @param string              $group
     * @param string              $stream
     * @param object              $options ['retention_days' => 90]
     * 
     * @return array              ['stream' => array|null, 'group' => array|null]
     */
    protected function createGroupAndStream($group, $stream, $options = null)
    {       
        $existingGroup = $this->getLogGroup($group);
        
        if (!isset($existingGroup)) {
            $this->createLogGroup($group);

            $retention = isset($options) && isset($options->retention_days) ? $options->retention_days : null;
            $this->putRetentionPolicy($group, $retention);
        }

        $existingStream = $this->getLogStream($group, $stream);
        if (!isset($existingStream)) {
            $this->createStream($group, $stream);
        }

        return [
            'group' => $existingGroup,
            'stream' => $existingStream
        ];
    }
    
    
    /**
     * Put log events
     * 
     * @param array  $events
     * @param string $group
     * @param string $stream
     * @param string sequenceToken  Omit if the stream does not exist yet
     * 
     * @return Result
     */
    public function putLogEvents($events, $group, $stream, $sequenceToken = null)
    {
        $data = [
            'logEvents' => $events,
            'logGroupName' => $group,
            'logStreamName' => $stream
        ];
        
        if (isset($sequenceToken)) {
            $data['sequenceToken'] = $sequenceToken;
        }
        
        return $this->client->putLogEvents($data);
    }
    
    
    /**
     * Create log group
     * 
     * @param string $group
     * 
     * return Result
     */
    public function createLogGroup($group)
    {
        return $this->client->createLogGroup(['logGroupName' => $group]);
    }
    
    /**
     * Get log group information
     * 
     * @param string $group
     * 
     * @return array|null
     */
    public function getLogGroup($group)
    {
        $groups = $this->client->describeLogGroups([
            'logGroupNamePrefix' => $group
        ])->get('logGroups');
        
        foreach ($groups as $data) {
            if ($data['logGroupName'] !== $group) {
                continue;
            }
            
            return $data;
        }
    }
    
    
    /**
     * Put retention policy
     * 
     * @param string   $group
     * @param int|null $retention  Omit for indefinitely
     * 
     * return Result
     */
    public function putRetentionPolicy($group, $retention = null)
    {
        return $this->client->putRetentionPolicy([
            'logGroupName' => $group,
            'retentionInDays' => $retention
        ]);
    }
    
    
    /**
     * Create stream
     * 
     * @param string $group
     * @param string $stream
     * 
     * return Result
     */
    public function createStream($group, $stream)
    {
        return $this->client->createLogStream([
            'logGroupName' => $group,
            'logStreamName' => $stream
        ]);
    }
    
    /**
     * Get stream information
     * 
     * @param string $group
     * @param string $stream
     * 
     * @return array|null
     */
    public function getLogStream($group, $stream)
    {
        $streams = $this->client->describeLogStreams([
            'logGroupName' => $group,
            'logStreamNamePrefix' => $stream
        ])->get('logStreams');
        
        foreach ($streams as $data) {
            if ($data['logStreamName'] !== $stream) {
                continue;
            }
            
            return $data;
        }
    }
}
