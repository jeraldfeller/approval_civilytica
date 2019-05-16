<?php

use Aiden\Models\Das;

class WaverleyTask extends _BaseTask {

    public $council_name = "Waverley";
    public $council_website_url = "www.waverley.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData) {

        $this->logger->info("Accepting terms...");

        $url = "https://eservices.waverley.nsw.gov.au/Common/Common/terms.aspx";

        // Add extra values
        $formData["ctl00_rcss_TSSM"] = null;
        $formData['ctl00$ctMain$BtnAgree'] = "I Agree";
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

        $url = "http://eservices.waverley.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx"
            . "?d=" . $params[0]
            . "&k=LodgementDate"
            . "&t=A0,SP2A,TPO,B1,B1A,FPS";

        $this->logger->info($url);

        // Accept terms
        $this->acceptTerms($this->getAspFormDataByUrl($url));

        // Make sure to get XML output
        $url .= "&o=xml";

        $requestHeaders = [
            "Accept: */*; q=0.01",
            "Accept-Encoding: none"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
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
                continue;
            }

            $daCouncilReferenceElement = $daHtml->find("ReferenceNumber", 0);
            $daCouncilReferenceAltElement = $daHtml->find("ApplicationId", 0);

            if ($daCouncilReferenceElement === null || $daCouncilReferenceAltElement === null) {
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->innertext());
            $daCouncilReferenceAlt = $this->cleanString($daCouncilReferenceAltElement->innertext());
            $daCouncilUrl = "https://eservices.waverley.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx?id=" . $daCouncilReferenceAlt;

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

    public function scrapeMetaAction() {

        $this->logger->info("This council offers most information in their XML pages, so all of the "
            . "information for a development application is pulled from the initial scrape()-method.");
        return false;

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $addressElements = $html->find("Address");

        foreach ($addressElements as $addressElement) {

            $addressHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($addressElement->innertext());
            if ($addressHtml === null) {
                continue;
            }

            $line1Element = $addressHtml->find("Line1", 0);

            if ($line1Element === null) {
                continue;
            }

            $address = $this->cleanString($line1Element->innertext());
            if (strlen($address) > 0 && $this->saveAddress($da, $address)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $da->getCouncilUrl());
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

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$docsHtml) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $anchorElements = $docsHtml->find("a");
        foreach ($anchorElements as $anchorElement) {

            $regexPattern = '/Common\/Output\/TrimWS\.aspx\?id=/';
            if (preg_match($regexPattern, $anchorElement->href) === 0) {
                continue;
            }

            $firstChild = $anchorElement->children(0);
            if ($firstChild !== null && $firstChild->tag === "img") {
                continue;
            }

            $documentUrl = "https://eservices.waverley.nsw.gov.au";
            $documentUrl .= $this->cleanString($anchorElement->href);

            $documentName = $this->cleanString($anchorElement->innertext());

            $documentDate = null;

            $anchorParentElement = $anchorElement->parent();
            if ($anchorParentElement !== null) {

                $dateElement = $anchorParentElement->next_sibling();
                if ($dateElement !== null) {

                    $date = $this->cleanString($dateElement->innertext());
                    $documentDate = \DateTime::createFromFormat("d M Y", $date);
                }
            }

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate) === true) {
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
        return (strlen($estimatedCost) > 0 && $this->saveEstimatedCost($da, $estimatedCost));

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $lodgeDateElement = $html->find("LodgementDate", 0);
        if ($lodgeDateElement === null) {
            return false;
        }

        $value = $this->cleanString($lodgeDateElement->innertext());
        $dateParts = explode("T", $value);
        $date = \DateTime::createFromFormat("Y-m-d", $dateParts[0]);
        return $this->saveLodgeDate($da, $date);

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $partyElements = $html->find("Party");

        foreach ($partyElements as $partyElement) {

            $partyHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($partyElement->innertext());
            if ($partyHtml === null) {
                continue;
            }

            $nameElement = $partyHtml->find("FullName", 0);
            if ($nameElement === null) {
                continue;
            }

            $role = "";
            $name = $this->cleanString($nameElement->innertext());

            $roleElement = $partyHtml->find("PartyRole", 0);
            if ($roleElement !== null) {

                $role = $this->cleanString($roleElement->innertext());
            }

            if ($this->saveParty($da, $role, $name)) {
                $addedPeople++;
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

}
