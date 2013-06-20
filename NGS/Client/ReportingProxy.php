<?php
namespace NGS\Client;

require_once(__DIR__.'/../Utils.php');
require_once(__DIR__.'/../Name.php');
require_once(__DIR__.'/RestHttp.php');
require_once(__DIR__.'/QueryString.php');

use NGS\Converter\PrimitiveConverter;
use NGS\Converter\ObjectConverter;
use NGS\Name;
use NGS\Patterns\Specification;
use NGS\Patterns\GenericSearch;

/**
 * Proxy for executing reporting commands: creating and populating reports,
 * OLAP reports, searching command history
 */
class ReportingProxy
{
    const REPORTING_URI = 'Reporting.svc';

    protected $http;

    protected static $instance;

    /**
     * Create a new ReportingProxy instance
     *
     * @param RestHttp $http RestHttp instance used for http request.
     * Optionally specify an instance, otherwise use a singleton instance
     */
    public function __construct(RestHttp $http = null)
    {
        $this->http = $http !== null ? $http : RestHttp::instance();
    }

    /**
     * Gets singleton instance of Reporting.svc proxy
     * 
     * @return ReportingProxy
     */
    public static function instance()
    {
        if(self::$instance === null)
            self::$instance = new ReportingProxy();
        return self::$instance;
    }

    /**
     * Populates report with data
     *
     * @param $report
     * @return mixed
     */
    public function populateReport($report)
    {
        $class = get_class($report);
        $name = Name::full($report);
        $response =
        $this->http->sendRequest(
            self::REPORTING_URI.'/report/'.rawurlencode($name),
            'PUT',
            $report->toJson(),
            array(200));
        return RestHttp::parseResult($response, $class);
    }

    /**
     * Creates report using Templater
     * 
     * @param  mixed  $report    Report instance
     * @param  string $templater Templater name
     * @return string            Report contents
     */
    public function createReport($report, $templater)
    {
        $name = Name::full($report);
        return
            $this->http->sendRequest(
                self::REPORTING_URI.'/report/'.rawurlencode($name).'/'.rawurlencode($templater),
                'PUT',
                $report->toJson(),
                array(201),
                'application/octet-stream');
    }

    /**
     * Create report with Templater using OLAP with specification as data source
     * 
     * @param  \NGS\Patterns\OlapCube      $cube
     * @param  \NGS\Patterns\Specification $specification
     * @param  string                      $templater
     * @param  array                       $dimensions
     * @param  array                       $facts
     * @param  array                       $order
     * @return string Report contents
     */
    public function olapCubeWithSpecification(
        $cube,
        $specification,
        $templater,
        array $dimensions,
        array $facts,
        array $order = array())
    {
        $cube = Name::full($cube);
        $name = Name::base($specification);
        $fullName = Name::full($specification);
        if(strncmp($fullName, $cube, strlen($cube)) != 0)
            $name = substr($fullName, 0, strlen($fullName) - strlen($name) - 1).'+'.$name;
        $arguments = QueryString::prepareCubeCall($dimensions, $facts, $order);
        return
            $this->http->sendRequest(
                self::REPORTING_URI.'/olap/'.rawurlencode($cube).'/'.rawurlencode($templater).'?specification='.rawurlencode($name).'&'.$arguments,
                'PUT',
                $specification->toJson(),
                array(201),
                'application/octet-stream');
    }

    /**
     * Create report with Templater using OLAP as data source
     *
     * @param \NGS\Patterns\OlapCube $cube
     * @param  string                      $templater
     * @param  array                       $dimensions
     * @param  array                       $facts
     * @param  array                       $order
     * @return string Report contents
     */
    public function olapCube(
        $cube,
        $templater,
        array $dimensions,
        array $facts,
        array $order = array())
    {
        $cube = Name::full($cube);
        $arguments = QueryString::prepareCubeCall($dimensions, $facts, $order);
        return
            $this->http->sendRequest(
                self::REPORTING_URI.'/olap/'.rawurlencode($cube).'/'.rawurlencode($templater).'?'.$arguments,
                'GET',
                null,
                array(201),
                'application/octet-stream');
    }

    /**
     * Gets all history entries for an identifiable object
     * 
     * @param  string $class Object class name
     * @param  string $uri   Object URI
     * @return array         List of history entries
     */
    public function getHistory($class, $uri)
    {
        return is_array($uri)
            ? $this->getCommandHistory($class, $uri)
            : $this->getRestHistory($class, $uri);
    }

    private static function parseHistoryResponse($response, $class)
    {
        $data = json_decode($response, true);
        $result = array();
        $converter = ObjectConverter::getConverter($class, ObjectConverter::ARRAY_TYPE);

        foreach($data as $dataItem)
        {
            $history = array();
            $snapshots = $dataItem['Snapshots'];
            foreach($snapshots as $snapshotItem)
            {
                $history[] =
                    new \NGS\Patterns\Snapshot(
                        $snapshotItem['At'],
                        $snapshotItem['Action'],
                        $converter::fromArray($snapshotItem['Value']));
            }
            $result[] = $history;
        }

        return $result;
    }

    private function getCommandHistory($class, $uris)
    {
        $name = Name::full($class);
        $body = array('Name' => $name, 'Uri' => PrimitiveConverter::toStringArray($uris));
        $response =
            $this->http->sendRequest(
                ApplicationProxy::APPLICATION_URI.'/GetRootHistory',
                'POST',
                json_encode($body),
                array(200));
        return self::parseHistoryResponse($response, $class);
    }

    private function getRestHistory($class, $uri)
    {
        $name = Name::full($class);
        $response =
            $this->http->sendRequest(
                self::REPORTING_URI.'/history/'.rawurlencode($name).'/'.rawurlencode($uri),
                'GET',
                null,
                array(200));
        $history = self::parseHistoryResponse($response, $class);
        return isset($history[0]) ? $history[0] : array();
    }

    /**
     * Populates template with identifiable object
     * 
     * @param  string $file  Template file to populate
     * @param  string $class Object class
     * @param  string $uri   Object URI 
     * @return string        Populated template contents
     */
    public function findTemplater(
        $file,
        $class,
        $uri=null)
    {
        $name = Name::full($class);
        $uriQuery = $uri!==null ? '/'.$uri : '';
        $response =
            $this->http->sendRequest(
                self::REPORTING_URI.'/templater/'.rawurlencode($file).'/'.rawurlencode($name).$uriQuery,
                'GET',
                null,
                array(200));
        return RestHttp::parseResult($response, $class);
    }

    /**
     * Populates template using specification search results
     * 
     * @param  string $file  Template file to populate
     * @param  string \NGS\Patterns\Specification Specification to be searched
     * @return string        Populated template contents
     */
    public function searchTemplater(
        $file,
        Specification $specification)
    {
        $object = Name::parent($specification);
        $name = Name::base($specification);
        $response =
            $this->http->sendRequest(
                self::REPORTING_URI.'/templater/'.rawurlencode($file).'/'.rawurlencode($object).'?specification='.rawurlencode($name),
                'PUT',
                $specification->toJson(),
                array(200));
        return RestHttp::parseResult($response, Name::toClass($object));
    }

    /**
     * Populates template using generic search results
     * 
     * @param  string $file  Template file to populate
     * @param  string \NGS\Patterns\GenericSearch
     * @return string        Populated template contents
     */
    public function searchTemplaterGeneric(
        $file,
        GenericSearch $search)
    {
        $object = Name::full($search->getObject());
        $response =
            $this->http->sendRequest(
                self::REPORTING_URI.'/templater-generic/'.rawurlencode($file).'/'.rawurlencode($object),
                'PUT',
                json_encode($search->getFilters()),
                array(200));
        return RestHttp::parseResult($response, Name::toClass($object));
    }
}