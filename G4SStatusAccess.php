<?php
require_once 'vendor/autoload.php';
use Goutte\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Cookie\SetCookie;

class G4SStatusAccess
{
  private $loginID = '';
  private $passWord = '';
  private $passCode = '';
  private $openHabUser ='';
  private $openHabPassword ='';
  private $openHabIp ='';
  
  function __construct($loginID, $passCode, $openHabUser, $openHabPassword, $openHabIp, $openHabPort)
    {
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
    $savedArmState = null;
    while(true)
    {
      $cookieJar = new \Symfony\Component\BrowserKit\CookieJar();
      $client = new Client(array(), null, $cookieJar);
      $guzzleClient = new \GuzzleHttp\Client(['verify' => false,'debug'=>false,'cookies' => true]);
      $client->setClient($guzzleClient);
      date_default_timezone_set('Europe/Copenhagen');
      
      $crawler = $client->request('GET', 'https://homelink.g4s.dk/ELAS/WUApp/MainPage.aspx');
      
      $html = $crawler->html();
      $html = str_replace("</form>", "<input name='__EVENTTARGET'><input type='submit' value='x'></form>", $html);
      /*
        Remember to comment out line 299 in vendor\symfony\dom-crawler\Crawler.php
      */
      $crawler->add($html);
      $form = $crawler->selectButton('x')->form();


      $response = $client->submit($form, array('LoginID' => $this->loginID, 'Password' =>  $this->passWord, 'PassCode' => $this->passCode, '__EVENTTARGET' => 'SignInBtn'));
      $response->filter('#CPStatusA')->each(function ($node) use (&$savedArmState){
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
        if($savedArmState==$cpStatus)
        {
          //NOP
        }
        else
        {
          $this->sendCommand('Alarm',$node->text());
        }
        echo PHP_EOL;
        $savedArmState=$cpStatus;
      });
      $aspCookie = $cookieJar->get('ASP.NET_SessionId');
      $elasWUNAppCookie = $cookieJar->get('ElasWUNAppCookie');
      if(is_null($aspCookie) OR is_null($elasWUNAppCookie))
      {
        echo 'aspCookie or elasWUNAppCookie is null - Continuing' . PHP_EOL;
        continue;
      }
      $cookieString = $aspCookie->getName().'='.$aspCookie->getValue().'; '.$elasWUNAppCookie->getName().'='.$elasWUNAppCookie->getValue();
      
      $request = new Request('POST', 'https://homelink.g4s.dk/ELAS/WUAPP/ajaxpro/WUNApp.BasicPage,WUNApp.ashx', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Ajax-method' => 'GetCPInfo',
            'Ajax-session'=> 0,
            'Ajax-token' => 'ajaxpro',
            'Accept' =>'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Accept-Language' => 'da,en-US;q=0.7,en;q=0.3',
            'Connection' => 'keep-alive',
            'Referer' => 'https://homelink.g4s.dk/ELAS/WUAPP/noskin/home.aspx',
            'Origin'=>'https://homelink.g4s.dk',
            'Cookie' => $cookieString
        ]);
        
      $gotSameTmsCounter=1;
      while ($gotSameTmsCounter<5)
      {
        sleep(15);
        $response=null;
        $response = $guzzleClient->send($request);
        $cPInfo=null;
        $cPInfo = json_decode($response->getBody());
        if($savedTime==$cPInfo->CurTime)
        {
          $gotSameTmsCounter++;
        }
        else
        {
          $gotSameTmsCounter=1;
        }
        $savedTime=$cPInfo->CurTime;
        $date = date('Y-m-d H:i:s');
        echo $date . ' - ';
        echo $response->getHeaders()['Date'][0] . ' - ';
        echo $response->getStatusCode() . ' - ';
        echo $cPInfo->ArmState . ' - ';
        if($savedArmState==$cPInfo->ArmState)
        {
          //NOP
        }
        else
        {
          $this->sendCommand('Alarm',$cPInfo->ArmState);
        }
        $savedArmState=$cPInfo->ArmState;
        echo $cPInfo->CurTime . PHP_EOL;
      }
    }
    return;
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