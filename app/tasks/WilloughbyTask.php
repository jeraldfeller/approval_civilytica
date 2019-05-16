<?php

use Aiden\Models\Das;

class WilloughbyTask extends _BaseTask {

    public $council_name = "Willoughby";
    public $council_website_url = "www.willoughby.nsw.gov.au";
    public $council_params = [];
    public $council_default_param = "";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData) {

        $this->logger->info("Accepting terms...");
        $url = "https://eplanning.willoughby.nsw.gov.au/Common/Common/terms.aspx";

        // Add extra values
        $formData["ctl00_rcss_TSSM"] = null;
        $formData['ctl00$ctMain$BtnAgree'] = "I Agree";
        $formData['ctl00$ctMain$chkAgree$chk1'] = "on";
        $formData = http_build_query($formData);

        $requestHeaders = [
            "Accept: */*; q=0.01",
            "Accept-Encoding: none",
            "Content-Type: application/x-www-form-urlencoded",
            "Content-Length: " . strlen($formData)
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

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);

        curl_close($ch);

        if ($errno !== 0) {

            $message = "cURL error: " . $errmsg . " (" . $errno . ")";
            $this->logger->error($message);
            return false;
        }

        $this->logger->info("Terms accepted.");

    }

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = 'https://eplanning.willoughby.nsw.gov.au/pages/xc.track/searchapplication.aspx'
                . '?d=' . $params[0]
                . '&k=lodgementdate'
                . '&t=da01,da01a,da02a,da03,da05,da06,da07,da10,s96,cc01a,cc01b,cc03,cc04,cd01a,'
                . 'cd01b,cd02,cd04,bcertu,bcertr,bcertc,tvpa,tvpa%202,tvpa%20r';

        // Accept terms
        $this->acceptTerms($this->getAspFormDataByUrl($url));

        // Make sure to get XML output
        $url .= "&o=xml";
        $this->logger->info($url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
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

            echo $output;
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $daElements = $html->find("Application");
        foreach ($daElements as $daElement) {

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->innertext());
            if ($daHtml === false) {
                continue;
            }

            $daCouncilReferenceElement = $daHtml->find("ReferenceNumber", 0);
            $daCouncilReferenceAltElement = $daHtml->find("ApplicationId", 0);

            if ($daCouncilReferenceElement === null || $daCouncilReferenceAltElement === null) {
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->innertext());
            $daCouncilReferenceAlt = $this->cleanString($daCouncilReferenceAltElement->innertext());
            $daCouncilUrl = "https://eplanning.willoughby.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx?id=" . $daCouncilReferenceAlt;

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilUrl($daCouncilUrl);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);

            if ($this->saveDa($da)) {
                $this->scrapeMeta($daHtml, $da);
                $this->logger->info("");
            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $addressElements = $html->find("Address");

        foreach ($addressElements as $addressElement) {

            $addressHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($addressElement->innertext());
            if ($addressHtml === false) {
                continue;
            }

            $line1Element = $addressHtml->find("Line1", 0);
            if ($line1Element === null) {
                continue;
            }

            $address = $this->cleanString($line1Element->innertext());
            if (strlen($address) === 0) {
                continue;
            }

            $lotElement = $addressHtml->find("FullLegalDescription", 0);
            if ($lotElement !== null) {

                $lot = $this->cleanString($lotElement->innertext());
                $address = $lot . ", " . $address;
            }

            if ($this->saveAddress($da, $address)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $descriptionElement = $html->find("ApplicationDetails", 0);
        if ($descriptionElement === null) {
            return false;
        }

        $description = $this->cleanString($descriptionElement->innertext());
        return (strlen($description) > 0 && $this->saveDescription($da, $description));

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $url = "https://eplanning.willoughby.nsw.gov.au/pages/xc.track/Services/ECMConnectService.aspx/GetDocuments";

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
            $documentUrl = "https://eplanning.willoughby.nsw.gov.au" . $documentUrl;

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $estimatedCostElement = $html->find("EstimatedCost", 0);
        if ($estimatedCostElement === null) {
            return false;
        }

        $estimatedCost = $this->cleanString($estimatedCostElement->innertext());
        return $this->saveEstimatedCost($da, $estimatedCost);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $dateElement = $html->find("LodgementDate", 0);
        if ($dateElement === null) {
            return false;
        }

        $dateAndTimeString = $this->cleanString($dateElement->innertext());
        $dateString = explode("T", $dateAndTimeString)[0];
        $date = \DateTime::createFromFormat("Y-m-d", $dateString);

        return $this->saveLodgeDate($da, $date);

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $partyElements = $html->find("Party");

        foreach ($partyElements as $partyElement) {

            $partyHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($partyElement->innertext());
            if ($partyHtml === false) {
                continue;
            }

            $roleElement = $partyHtml->find("PartyRole", 0);
            $nameElement = $partyHtml->find("FullName", 0);

            if ($roleElement === null || $nameElement === null) {
                continue;
            }

            $role = $this->cleanString($roleElement->innertext());
            $name = $this->cleanString($nameElement->innertext());

            if ($this->saveParty($da, $role, $name)) {
                $addedPeople++;
            }
        }
        return ($addedPeople > 0);

    }

}
