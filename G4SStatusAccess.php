<?php
require_once 'vendor/autoload.php';
use Goutte\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Cookie\SetCookie;
use Symfony\Component\BrowserKit\CookieJar;

class G4SStatusAccess
{
  private $loginID = '';
  private $passWord = '';
  private $passCode = '';
  private $openHabUser ='';
  private $openHabPassword ='';
  private $openHabIp ='';
  private $savedArmState=null;
  private $savedTime=null;
  private $gotSameTmsCounter = 1;
  
  function __construct($loginID, $passCode, $openHabUser, $openHabPassword, $openHabIp, $openHabPort)
    {
      date_default_timezone_set('Europe/Copenhagen');
      $this->loginID=$loginID;
      $this->passWord='A'.$loginID;
      $this->passCode=$passCode;
      $this->openHabUser=$openHabUser;
      $this->openHabPassword=$openHabPassword;
      $this->openHabIp=$openHabIp;
      $this->openHabPort=$openHabPort;
    }
  
  
  public function fire()
  {
    while(true)
    {
      $cookieJar = new CookieJar();
          
      $client = $this->setupGoutteClient($cookieJar);
      
      $crawler = $client->request('GET', 'https://homelink.g4s.dk/ELAS/WUApp/MainPage.aspx');
      
      $form = $this->getModdedForm($crawler);
      
      $response = $client->submit($form, array('LoginID' => $this->loginID, 'Password' =>  $this->passWord, 'PassCode' => $this->passCode, '__EVENTTARGET' => 'SignInBtn'));
      
      $cpStatus = $this->retrieveStatusFromResponse($response);
      
      $this->ensureSmartHomeUpdate($cpStatus);
      
      echo PHP_EOL;

      $cookieString = $this->extractCookieString($cookieJar);
      if(!$cookieString)
      {
        echo 'aspCookie or elasWUNAppCookie is null - Continuing' . PHP_EOL;
        continue;
      }
    
      $request = $this->buildAjaxRequest($cookieString);
      
      $guzzleClient = $this->convertToGuzzleClient($client);  
        
      $this->gotSameTmsCounter=0; //Reset counter
      while ($this->gotSameTmsCounter<5)
      {
        sleep(15);
        try
        {
          $promise = $guzzleClient->sendAsync($request);
          $promise->then(function ($response) {
            echo $this->gotSameTmsCounter . ' - ';
            
          
            $cPInfo = $this->retrieveStatusFromAjaxResponse($response);
            
            if($cPInfo==null)
              echo 'NULL - '; //actually should do something with continue
            
            $this->timeFromAlarmPanelDuplicateNumber($cPInfo->CurTime);

            $this->ensureSmartHomeUpdate($cPInfo->ArmState);
          });
          $promise->wait();
        }
        catch (\GuzzleHttp\Exception\ServerException $e)
        {
          echo 'Got server-exception: '. $e->getMessage();
          break;
        }
        
      }
      
    }
    return;
  }
  
  private function setupGoutteClient($cookieJar)
  {
    $client = new Client(array(), null, $cookieJar);
    $guzzleClient = new \GuzzleHttp\Client(['verify' => false,'debug'=>false,'cookies' => true]);
    $client->setClient($guzzleClient);
    return $client;
  }
  
  private function getModdedForm($crawler)
  {
    $html = $crawler->html();
    $html = str_replace("</form>", "<input name='__EVENTTARGET'><input type='submit' value='x'></form>", $html);
    /*
      Remember to comment out line 299 in vendor\symfony\dom-crawler\Crawler.php
    */
    $crawler->add($html);
    return $crawler->selectButton('x')->form();
  }
  
  private function retrieveStatusFromResponse($response)
  {
    $cpStatus = null;
    $response->filter('#CPStatusA')->each(function ($node) use (&$cpStatus){
      echo '0 - ';
      $date = date('Y-m-d H:i:s');
      echo $date . ' - ';
      echo '                             ' . ' - ';
      echo '   ' . ' - ';
      if (strpos($node->text(), '-') !== FALSE)
      {
        $cpStatus = strtok($node->text(), ' ');
      }
      else
      {
        $cpStatus = $node->text();
      }
      echo $cpStatus  . ' - ';
      
    });
    return $cpStatus;
  }
  
  private function retrieveStatusFromAjaxResponse($response)
  {
    $cPInfo = json_decode($response->getBody()); 
    $date = date('Y-m-d H:i:s');
    echo $date . ' - ';
    echo $response->getHeaders()['Date'][0] . ' - ';
    echo $response->getStatusCode() . ' - ';
    echo $cPInfo->ArmState . ' - ';
    echo $cPInfo->CurTime . PHP_EOL;
    return $cPInfo;
  }
  
  private function ensureSmartHomeUpdate($cpStatus)
  {
    if($this->savedArmState==$cpStatus)
    {
      //NOP
    }
    else
    {
      $this->sendCommand('Alarm',$cpStatus);
      $this->savedArmState=$cpStatus;
    }
  }
  
  private function extractCookieString($cookieJar)
  {
    $aspCookie = $cookieJar->get('ASP.NET_SessionId');
    $elasWUNAppCookie = $cookieJar->get('ElasWUNAppCookie');
    if(is_null($aspCookie) OR is_null($elasWUNAppCookie))
    {
      return null;
    }
    else {
      $cookieString = $aspCookie->getName().'='.$aspCookie->getValue().'; '.$elasWUNAppCookie->getName().'='.$elasWUNAppCookie->getValue();
      return $cookieString;
    }
    
  }
  
  private function convertToGuzzleClient($client)
  {
    return $client->getClient();
  }
  
  private function buildAjaxRequest($cookieString)
  {
    return new Request('POST', 'https://homelink.g4s.dk/ELAS/WUAPP/ajaxpro/WUNApp.BasicPage,WUNApp.ashx', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Ajax-method' => 'GetCPInfo',
            'Ajax-session'=> 0,
            'Ajax-token' => 'ajaxpro',
            'Accept' =>'*/*',
            'Accept-Encoding' => 'gzip, deflate',
            'Accept-Language' => 'da,en-US;q=0.8,en;q=0.6',
            'Connection' => 'keep-alive',
            'Referer' => 'https://homelink.g4s.dk/ELAS/WUAPP/noskin/home.aspx',
            'Origin'=>'https://homelink.g4s.dk',
            'Cookie' => $cookieString,
        ], ' ');
  }
  
  private function timeFromAlarmPanelDuplicateNumber($timeFromAlarmPanel)
  {
    if($this->savedTime==$timeFromAlarmPanel)
    {
      $this->gotSameTmsCounter++;
    }
    else
    {
      $this->gotSameTmsCounter=1;
    }
    $this->savedTime=$timeFromAlarmPanel;
  }
  
  public function sendCommand($item, $data) {
    echo 'Sending - ';
      $url = 'http://'.$this->openHabUser.':'.$this->openHabPassword.'@'.$this->openHabIp.':'.$this->openHabPort.'/rest/items/' . $item;
      $options = array(
        'http' => array(
            'header'  => "Content-type: text/plain\r\n",
            'method'  => 'POST',
            'content' => $data  //http_build_query($data),
        ),
      );

      $context  = stream_context_create($options);
      $result = file_get_contents($url, false, $context);

      return $result;
    }

}

$obj = new G4SStatusAccess($argv[1],$argv[2],$argv[3],$argv[4],$argv[5],$argv[6]);
$obj->fire();

?>