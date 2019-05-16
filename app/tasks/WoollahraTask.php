<?php

use Aiden\Models\Das;

class WoollahraTask extends _BaseTask
{

    public $council_name = "Woollahra";
    public $council_website_url = "https://www.woollahra.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    public function scrapeAction($params = [])
    {

//        $url = "https://eservices.woollahra.nsw.gov.au/eservice/advertisedDAs.do?orderBy=suburb&nodeNum=5265";
        $dateFrom = str_replace('-', '%2F', date('01-m-Y'));
        $dateTo = str_replace('-', '%2F', date('01-m-Y'));

        $url = 'https://eservices.woollahra.nsw.gov.au/eservice/daEnquiry/recentlyDetermined.do?num_days=90&nodeNum=5263';
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


        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        // Get each suburb through h4
        $suburbHeaderElements = $html->find(".non_table_headers");
        foreach ($suburbHeaderElements as $suburbHeaderElement) {
            $suburbName = $this->cleanString(strip_tags($suburbHeaderElement->innertext()));
            $url = 'https://eservices.woollahra.nsw.gov.au' . $suburbHeaderElement->find('a', 0)->getAttribute('href');
            $infoContainer = $suburbHeaderElement->next_sibling();
            $this->logger->info($suburbName . ' - ' . $url);

            $rowData = $infoContainer->find('.rowDataOnly');
            $reference = '';

            foreach ($rowData as $row) {

                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);

                switch ($key) {
                    case 'Applicant':
                        $applicant = explode(',', $inputField);
                        break;
                    case 'Certifier':
                        $certifier = $inputField;
                        break;
                    case 'Application No.':
                        $reference = $inputField;
                        break;
                    case 'Date Lodged':
                        $lodgeDate = \DateTime::createFromFormat("m/d/Y", $inputField);
                        break;
                    case 'Cost of Work':
                        $cost = $inputField;
                        break;
                }

            }


            $da = Das::exists($this->getCouncil()->getId(), $reference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilReference($reference);
            $da->setCouncilUrl($url);


            if ($this->saveDa($da) === true) {

                $daHtml = $this->scrapeTo($url);
                $this->scrapeMeta($daHtml, $da);
                $this->logger->info("");
            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool
    {

        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $row->find('.inputField');
                if ($key == 'Property Details') {
                    for($x = 0; $x < count($inputField); $x++){
                        $address = $this->cleanString($inputField[$x]->plaintext);
                        $this->saveAddress($da, $address);
                    }

                }
            }
        }

        return (strlen($address) > 0 ? true : false);

    }

    protected function extractApplicants($html, $da, $params = null): bool
    {

        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);
                if ($key == 'Applicant') {
                    $applicant = explode(',', $inputField);
                }
            }
        }

        for ($a = 0; $a < count($applicant); $a++) {
            $this->saveParty($da, 'Applicant', $applicant[$a]);
        }


        return (count($applicant) > 0 ? true : false);

    }

    protected function extractDescription($html, $da, $params = null): bool
    {

        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);
                if ($key == 'Type of Work') {
                    $description = $inputField;
                }
            }
        }

        return (strlen($description) > 0 && $this->saveDescription($da, $description));


    }

    protected function extractDocuments($html, $da, $params = null): bool
    {
        $addedDocuments = 0;
        $tables = $html->find('table');
        foreach ($tables as $table) {
            $summary = $table->getAttribute('summary');
            if($summary == 'Electronic Documents Associated this Development Application'){
                $tr = $table->find('tr');
                if($tr){
                    foreach($tr as $row){
                        $td = $row->find('td', 1);
                        if($td){
                            $a = $td->find('a', 0);
                            $documentName = $this->cleanString($a->innertext());
                            $documentUrl = 'https://eservices.woollahra.nsw.gov.au'.$a->getAttribute('href');
                            if ($this->saveDocument($da, $documentName, $documentUrl)) {
                                $addedDocuments++;
                            }
                        }
                    }
                }

            }
        }

        return true;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool
    {
        $cost = 0;
        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);
                if ($key == 'Cost of Work') {
                    $cost = $inputField;
                }
            }
        }

        $this->saveEstimatedCost($da, $cost);
        return ($cost != 0 ? true : false);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool
    {

        $lodgeDate = '';
        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);
                if ($key == 'Date Lodged') {
                    $lodgeDate = \DateTime::createFromFormat("d/m/Y", $inputField);
                    $this->saveLodgeDate($da, $lodgeDate);
                }
            }
        }


        return ($lodgeDate != '' ? true : false);

    }

    protected function extractOfficers($html, $da, $params = null): bool
    {

        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);
                if ($key == 'Liaison Officer') {
                    $officer = $inputField;
                    $this->saveParty($da, $key, $officer);
                }
            }
        }

        return true;

    }

    protected function extractPeople($html, $da, $params = null): bool
    {

        $rowData = $html->find('.rowDataOnly');
        foreach ($rowData as $row) {
            if ($row->find('.key', 0)) {
                $key = $this->cleanString($row->find('.key', 0)->plaintext);
                $inputField = $this->cleanString($row->find('.inputField', 0)->plaintext);
                if ($key == 'Certifier') {
                    $officer = $inputField;
                    $this->saveParty($da, $key, $officer);
                }
                if ($key == 'Owner') {
                    $officer = explode('<p', $row->find('.inputField', 0)->innertext);
                    $officer = $this->cleanString($officer[0]);
                    $this->saveParty($da, $key, $officer);
                }
            }
        }

        return true;

    }

    function scrapeTo($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
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


        return \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
    }

}
