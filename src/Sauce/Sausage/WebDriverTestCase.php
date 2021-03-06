<?php
namespace Sauce\Sausage;

abstract class WebDriverTestCase extends \PHPUnit_Extensions_Selenium2TestCase
{
    public static $browsers = array();

    protected $start_url = '';
    protected $base_url = NULL;
    protected $is_local_test = false;
    protected $build = false;
    protected $fileDetectorFunction = NULL;

    public function setUp()
    {
        $caps = $this->getDesiredCapabilities();
        $this->setBrowserUrl('');
        if (!isset($caps['name'])) {
            $caps['name'] = get_called_class().'::'.$this->getName();
            $this->setDesiredCapabilities($caps);
        }
    }

    public function setUpPage()
    {
        if ($this->start_url)
            $this->url($this->start_url);
    }

    public function setupSpecificBrowser($params)
    {
        // Setting 'local' gives us nice defaults of localhost:4444
        $local = (isset($params['local']) && $params['local']);
        $this->is_local_test = $local;

        if (!$local)
            SauceTestCommon::RequireSauceConfig();

        // Give some nice defaults
        if (!isset($params['seleniumServerRequestsTimeout']))
            $params['seleniumServerRequestsTimeout'] = 60;

        if (!isset($params['browserName'])) {
            $params['browserName'] = 'chrome';
            $params['desiredCapabilities'] = array(
                'version' => '',
                'platform' => 'VISTA'
            );
        }


        // Set up host

        $host = isset($params['host']) ? $params['host'] : false;
        if ($local) {
            $this->setHost($host ? $host : 'localhost');
        } else {
            $sauce_host = SAUCE_USERNAME.':'.SAUCE_ACCESS_KEY.'@ondemand.saucelabs.com';
            $this->setHost($host ? $host : $sauce_host);
        }

        // Set up port
        $port = isset($params['port']) ? $params['port'] : false;
        $this->setPort($port ? $port : ($local ? 4444 : 80));

        // Set up other params
        $this->setBrowser($params['browserName']);
        $caps = isset($params['desiredCapabilities']) ? $params['desiredCapabilities'] : array();
        $this->setSeleniumServerRequestsTimeout(
            $params['seleniumServerRequestsTimeout']);
        $build = isset($params['build']) ? $params['build'] : SauceConfig::GetBuild();
        if ($build && !isset($caps['build']))
            $caps['build'] = $build;
        $this->setDesiredCapabilities($caps);

        // If we're using Sauce, make sure we don't try to share browsers
        if (!$local && !$host && isset($params['sessionStrategy'])) {
            $params['sessionStrategy'] = 'isolated';
        }

        $this->setUpSessionStrategy($params);
    }

    public function isTextPresent($text, \PHPUnit_Extensions_Selenium2TestCase_Element $element = NULL)
    {
        $element = $element ?: $this->byCssSelector('body');
        $el_text = str_replace("\n", " ", $element->text());
        return strpos($el_text, $text) !== false;
    }

    public function waitForText($text, \PHPUnit_Extensions_Selenium2TestCase_Element $element = NULL,
        $timeout = 10)
    {
        $element = $element ?: $this->byCssSelector('body');
        $test = function() use ($element, $text) {
            $el_text = str_replace("\n", " ", $element->text());
            return strpos($el_text, $text) !== false;
        };
        $this->spinWait("Text $text never appeared!", $test, array(), $timeout);
    }


    public function assertTextPresent($text, \PHPUnit_Extensions_Selenium2TestCase_Element $element = NULL)
    {
        if ($element === NULL)
            $element = $this->byCssSelector('body');

        $this->spinAssert("$text was never found", function() use ($text, $element) {
            return strpos($element->text(), $text) !== false;
        });
    }

    public function assertTextNotPresent($text, \PHPUnit_Extensions_Selenium2TestCase_Element $element = NULL)
    {
        $element = $element ?: $this->byCssSelector('body');
        $this->spinAssert("$text was found", function() use ($text, $element) {
            return strpos($element->text(), $text) === false;
        });
    }

    public function byCss($selector)
    {
        return parent::byCssSelector($selector);
    }

    public function fileDetector($fileDetectorFunction)
    {
        $this->fileDetectorFunction = $fileDetectorFunction;
    }

    public function sendKeys(\PHPUnit_Extensions_Selenium2TestCase_Element $element, $keys)
    {
        if($this->fileDetectorFunction &&
            call_user_func($this->fileDetectorFunction, $keys)) {
            $remote_file = $this->file($keys);
            $element->value($remote_file);
        } else {
            $element->click();
            $this->keys($keys);
        }
    }

    public function tearDown()
    {
        if (!$this->is_local_test)
            SauceTestCommon::ReportStatus($this->getSessionId(), !$this->hasFailed());
    }

    public function spinAssert($msg, $test, $args=array(), $timeout=10)
    {
        list($result, $msg) = SauceTestCommon::SpinAssert($msg, $test, $args, $timeout);
        $this->assertTrue($result, $msg);
    }

    public function spinWait($msg, $test, $args=array(), $timeout=10)
    {
        list($result, $msg) = SauceTestCommon::SpinAssert($msg, $test, $args, $timeout);
        if (!$result)
            throw new \Exception($msg);
    }

    protected function buildUrl($url)
    {
        if ($url !== NULL && $this->base_url !== NULL && !preg_match("/^https?:/", $url)) {
            if (strlen($url) && $url[0] == '/') {
                $sep = '';
            } else {
                $sep = '/';
            }
            $url = trim($this->base_url, '/').$sep.$url;
        }
        return $url;
    }

    public function url($url = NULL)
    {
        return parent::url($this->buildUrl($url));
    }

    public function setBrowserUrl($url = '')
    {
        return parent::setBrowserUrl($this->buildUrl($url));
    }

    public function createNoLoginLink()
    {
        // generate as per http://saucelabs.com/docs/integration#public-job-links
        $job_id = $this->getSessionId();
        $key = SAUCE_USERNAME.':'.SAUCE_ACCESS_KEY;
        $auth_token = hash_hmac("md5", $job_id, $key);

        return "https://saucelabs.com/jobs/".$job_id."?auth=".$auth_token;
    }

    public function toString()
    {
        if(!$this->is_local_test && $this->hasFailed())
            return parent::toString()."\nReport link: ".$this->createNoLoginLink()."\n";
        return parent::toString();
    }

    public static function suite($className)
    {   
        self::setUpSauceOnDemandBrowsers();
        return parent::suite($className);
    } 

    public static function setUpSauceOnDemandBrowsers() {
        $json = getenv('bamboo_SAUCE_ONDEMAND_BROWSERS');
        if ($json) {
            self::$browsers = array_map(array('Sauce\Sausage\WebDriverTestCase','getSauceOnDemandBrowser'), json_decode($json));
        } else {
            self::$browsers = array(
                array(
                    'browserName' => 'firefox',
                    'desiredCapabilities' => array(
                        'platform' => 'Windows'
                    ),
                ),
            );
        }
    }

    public static function getSauceOnDemandBrowser($options) {
        $browser = array(
            'browserName' => $options->browser,
            'desiredCapabilities' => array(
                'platform' => $options->os,
                'version' => $options->{'browser-version'},
            ),
        );
        return $browser;
    }
}
