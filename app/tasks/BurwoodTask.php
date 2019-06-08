<?php

use Aiden\Models\Das;

class BurwoodTask extends _BaseTask
{

    public $council_name = "Burwood";

    public $council_website_url = "http://www.burwood.nsw.gov.au";

    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];

    public $council_default_param = "thismonth";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptedTerms()
    {

        $url = 'http://ecouncil.burwood.nsw.gov.au/Home/DisclaimerProcessing';
        $formData['agreed'] = "true";

        $formData = http_build_query($formData);

        $requestHeaders = [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3",
            "Content-Type: application/x-www-form-urlencoded",
            "Host: ecouncil.burwood.nsw.gov.au",
            "Origin: http://ecouncil.burwood.nsw.gov.au",
            "Referer: http://ecouncil.burwood.nsw.gov.au/Home/Disclaimer",
            "Content-Length: " . strlen($formData),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);

        curl_close($ch);

        if ($errno !== 0) {

            $message = "cURL error: " . $errmsg . " (" . $errno . ")";
            $this->logger->error($message);
            return false;
        }

    }

    /**
     * Request the initial page and receive a cookie so we can access the DAs
     */
    public function getUserContainer()
    {

        $url = "https://ecouncil.burwood.nsw.gov.au/eservice/daEnquiryInit.do?doc_typ=10&nodeNum=219";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {

            $logMsg = sprintf("cURL error: [%s] (%s)", $errmsg, $errno);
            $this->logger->info($logMsg);
            return false;
        }

        return true;

    }

    public function scrapeAction($params = [])
    {

        if (!isset($params[0])) {
            return false;
        }

        switch ($params[0]) {

            case "thisweek":
                $dateStart = new DateTime("next Monday -1 week");
                $dateEnd = new DateTime("next Monday");
                break;
            case "lastweek":
                $dateStart = new DateTime("next Monday -2 weeks");
                $dateEnd = new DateTime("next Monday -1 week");
                break;
            case "thismonth":
                $dateStart = new DateTime("first day of this month");
                $dateEnd = new DateTime("last day of this month");
                break;
            case "lastmonth":
                $dateStart = new DateTime("first day of last month");
                $dateEnd = new DateTime("last day of last month");
                break;
            default:
                return false;
        }

        // Request initial page to retrieve cookie so we're allowed access to DAs
        $this->acceptedTerms();

        $url = 'http://ecouncil.burwood.nsw.gov.au/Application/GetApplications';
        $postFields = [
            "draw" => 1,
            "columns[0][data]" => 0,
            "columns[0][searchable]" => true,
            "columns[0][orderable]" => false,
            "columns[0][search][regex]" => false,
            "columns[1][data]" => 1,
            "columns[1][searchable]" => true,
            "columns[1][orderable]" => false,
            "columns[1][search][regex]" => false,
            "columns[2][data]" => 2,
            "columns[2][searchable]" => true,
            "columns[2][orderable]" => false,
            "columns[2][search][regex]" => false,
            "columns[3][data]" => 3,
            "columns[3][searchable]" => true,
            "columns[3][orderable]" => false,
            "columns[3][search][regex]" => false,
            "columns[4][data]" => 4,
            "columns[4][searchable]" => true,
            "columns[4][orderable]" => false,
            "columns[4][search][regex]" => false,
            "start" => 0,
            "length" => 100,
            "search[regex]" => false,
            "json" => '{"ApplicationNumber":null,"ApplicationYear":null,"DateFrom":"' . $dateStart->format("d/m/Y") . '","DateTo":"' . $dateEnd->format("d/m/Y") . '","DateType":"2","RemoveUndeterminedApplications":true,"ApplicationDescription":null,"ApplicationType":null,"UnitNumberFrom":null,"UnitNumberTo":null,"StreetNumberFrom":null,"StreetNumberTo":null,"StreetName":null,"SuburbName":null,"PostCode":null,"PropertyName":null,"LotNumber":null,"PlanNumber":null,"ShowOutstandingApplications":false,"ShowExhibitedApplications":false,"PropertyKeys":null,"PrecinctValue":null,"IncludeDocuments":true}'

        ];

        $postFields = http_build_query($postFields);
        $requestHeaders = [
            "Accept: application/json, text/javascript, */*; q=0.01",
            "Content-Type: application/x-www-form-urlencoded",
            "Host: ecouncil.burwood.nsw.gov.au",
            "Origin: http://ecouncil.burwood.nsw.gov.au",
        ];


        echo $url . "\r\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $data = json_decode($output);
        $data = $data->data;
        for ($i = 0; $i < count($data); $i++) {
            $councilUrl = 'http://ecouncil.burwood.nsw.gov.au/Application/ApplicationDetails/' . $data[$i][0] . '/';
            $councilReference = $data[$i][1];
            $lodgementDate = $data[$i][3];
            $this->logger->info($councilReference);
            $da = Das::exists($this->getCouncil()->getId(), $councilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilReference($councilReference);
            $da->setCouncilUrl($councilUrl);
            $da->setLodgeDate(\DateTime::createFromFormat("d/m/Y", $lodgementDate));
            $this->saveDa($da);

        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $logMsg = "Done.";
        $this->scrapeMetaAction();
        $this->logger->info($logMsg);

    }
    
    protected function extractDescription($html, $da, $params = null): bool
    {

        $container = $html->find('#description', 0);
        $value = $this->cleanString($container->innertext());
        if($value != ''){
            return (strlen($value) > 0 && $this->saveDescription($da, $value));
        }
        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool
    {

        $container = $html->find('#estimatedCost', 0);
        $div = $container->next_sibling();
        if($div){
            $td = $div->find('td', 0);
            $value = $this->cleanString($td->innertext());
            return $this->saveEstimatedCost($da, $value);
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool
    {
        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool
    {

        $addedPeople = 0;
        $container = $html->find('#people', 0);
        $div = $container->next_sibling();
        if($div){
            $tr = $div->find('tr');
            foreach($tr as $row){
                $td = $row->find('td', 0);
                $text = trim($td->innertext());
                $textArr = explode(':', $text);
                $role = $this->cleanString($textArr[0]);
                $people = $this->cleanString($textArr[1]);
                if ($this->saveParty($da, $role, $people)) {
                    $addedPeople++;
                }

            }
        }

        return ($addedPeople > 0);

    }

    protected function extractAddresses($html, $da, $params = null): bool
    {

        $addressCount = 0;
        $value = $html->find('#property-list', 0);
        if($value){
            $addresses = explode('<br/>', $value->innertext());
            for($i = 0; $i < count($addresses); $i++){
                $address = $this->cleanString($addresses[$i]);
                $this->saveAddress($da, $address);
                $addressCount++;
            }

        }


        return ($addressCount > 0 ? true : false);

    }

    protected function extractApplicants($html, $da, $params = null): bool
    {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool
    {
        $value = $html->find('#officerName', 0);
        if($value){
            $officer = $this->cleanString($value->innertext());
            if ($this->saveParty($da, 'Officer', $officer)) {
               return true;
            }
        }
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool
    {
        return false;

    }

}
