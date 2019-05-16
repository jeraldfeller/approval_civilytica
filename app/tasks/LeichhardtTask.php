<?php

use Aiden\Models\Das;

class LeichhardtTask extends _BaseTask {

    public $council_name = "Leichhardt";
    public $council_website_url = "https://www.leichhardt.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";
    public $inner_west_id = 17;

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData) {

        $url = "http://eservices.lmc.nsw.gov.au/ApplicationTracking/Common/Common/terms.aspx";

        // Add extra values
        $formData["__EVENTTARGET"] = null;
        $formData["__EVENTARGUMENT"] = null;
        $formData['ctl00$ctMain$BtnAgree'] = "I Agree";

        $formData = http_build_query($formData);

        $requestHeaders = [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8",
            "Accept-Encoding: gzip, deflate",
            "Content-Type: application/x-www-form-urlencoded",
            "Content-Length: " . strlen($formData),
            "Host: eservices.lmc.nsw.gov.au",
            "Origin: http://eservices.lmc.nsw.gov.au",
            "Referer: http://eservices.lmc.nsw.gov.au/ApplicationTracking/Common/Common/terms.aspx",
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
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

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "http://www.eservices.lmc.nsw.gov.au/ApplicationTracking/Pages/XC.Track/SearchApplication.aspx"
                . "?d=" . $params[0]
                . "&k=LodgementDate"
                . "&t=161"
                . "&o=xml";

        $this->logger->info($url);

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
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $daElements = $html->find("Application");
        foreach ($daElements as $daElement) {

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->innertext());
            if ($daHtml === false) {
                $this->logger->error("Could not parse development application XML");
                continue;
            }

            $daCouncilReferenceElement = $daHtml->find("ReferenceNumber", 0);
            if ($daCouncilReferenceElement === null) {
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->innertext());

            $da = Das::exists($this->inner_west_id, $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->inner_west_id);
            $da->setCouncilReference($daCouncilReference);

            $daCouncilReferenceAltElement = $daHtml->find("ApplicationId", 0);
            if ($daCouncilReferenceAltElement === null) {
                $this->logger->error("Could not find alternative council reference, "
                        . "this is a critical field and without the development application URL cannot be generated");
                continue;
            }

            $daCouncilReferenceAlt = $this->cleanString($daCouncilReferenceAltElement->innertext());
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);

            $daCouncilUrl = "http://eservices.lmc.nsw.gov.au/ApplicationTracking/Pages/XC.Track/SearchApplication.aspx?id=" . $daCouncilReferenceAlt;
            $da->setCouncilUrl($daCouncilUrl);

            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $propertyElements = $html->find("a[title=Click to display property details]");

        $file = fopen("test.html","w");
        echo fwrite($file,$html);
        fclose($file);
        foreach ($propertyElements as $propertyElement) {

            $address = $this->cleanString($propertyElement->innertext());
            if (strlen($address) > 0 && $this->saveAddress($da, $address) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $valueElement = $detailsElement->children(0);
        if ($valueElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($valueElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        // First detail is the description, will only run 0 or 1 times
        foreach ($detailsArray as $detail) {
            return $this->saveDescription($da, $this->cleanString($detail));
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $url = "http://eservices.lmc.nsw.gov.au/ApplicationTracking/Pages/XC.Track/Services/ECMConnectService.aspx/GetDocuments";
        $jsonPayload = json_encode([
            "cId" => $da->getCouncilReferenceAlt(),
            "PageIndex" => 0,
            "PageSize" => 100
        ]);

        $requestHeaders = [
            "Accept: application/json, text/javascript, */*; q=0.01",
            "Content-Type: application/json; charset=utf-8",
            "Content-Length: " . strlen($jsonPayload),
            "Referer: " . $da->getCouncilUrl(),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
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
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $data = json_decode($output);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->info("Could not parse documents Json");
            return false;
        }

        if (strlen($data->d) === 0) {
            return false;
        }

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($data->d);
        if ($docsHtml === false) {
            return false;
        }

        $tableRowElements = $docsHtml->find("tr");
        foreach ($tableRowElements as $tableRowElement) {

            if (count($tableRowElement->children()) !== 4) {
                continue;
            }

            $rowHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($tableRowElement->innertext());
            if ($rowHtml === false) {
                continue;
            }

            $documentDateElement = $rowHtml->find("td", 0);
            if ($documentDateElement === 0) {
                continue;
            }

            $documentDateString = $this->cleanString($documentDateElement->innertext());
            $documentDate = \DateTime::createFromFormat("d/m/Y", $documentDateString);

            $documentNameParentElement = $rowHtml->find("td", 2);
            if ($documentNameParentElement === null) {
                continue;
            }

            $anchorElement = $documentNameParentElement->children(0);
            if ($anchorElement === null) {
                continue;
            }

            $documentName = $this->cleanString($anchorElement->innertext());

            $documentUrl = $this->cleanString($anchorElement->href);
            $documentUrl = str_replace("../../", "/", $documentUrl);
            $documentUrl = "http://eservices.lmc.nsw.gov.au/ApplicationTracking" . $documentUrl;

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $valueElement = $detailsElement->children(0);
        if ($valueElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($valueElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Estimated Cost of Work: \$ (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {
                return $this->saveEstimatedCost($da, $matches[1]);
            }
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $valueElement = $detailsElement->children(0);
        if ($valueElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($valueElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Submitted: ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {

                $date = \DateTime::createFromFormat("d/m/Y", $matches[1]);
                return $this->saveLodgeDate($da, $date);
            }
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $valueElement = $detailsElement->children(0);
        if ($valueElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($valueElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Officer: (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {

                $role = "Officer";
                $name = $this->cleanString($matches[1]);
                return $this->saveParty($da, $role, $name);
            }
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $peopleElement = $html->find("[id=b_ctl00_ctMain_info_party]", 0);

        if ($peopleElement === null) {
            return false;
        }

        $valueElement = $peopleElement->children(0);
        if ($valueElement === null) {
            return false;
        }

        $peopleString = $this->cleanString($valueElement->innertext());
        $peopleArray = explode("<br />", $peopleString);

        foreach ($peopleArray as $person) {

            $personArray = explode("-", $person);
            if (count($personArray) === 1) {

                $role = "Applicant";
                $name = $this->cleanString($personArray[0]);

                if (strlen($name) > 0 && $this->saveParty($da, $role, $name)) {
                    $addedPeople++;
                }
            }
            else if (count($personArray) === 2) {

                $role = $this->cleanString($personArray[0]);
                $name = $this->cleanString($personArray[1]);

                if (strlen($name) > 0 && $this->saveParty($da, $role, $name)) {
                    $addedPeople++;
                }
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
