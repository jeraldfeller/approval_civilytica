<?php

use Aiden\Models\Das;

class NorthsydneyTask extends _BaseTask {

    public $council_name = "North Sydney";
    public $council_website_url = "https://www.northsydney.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData) {

        $url = "https://apptracking.northsydney.nsw.gov.au/Common/Common/terms.aspx";

        // Add extra values
        $formData["ctl00_rcss_TSSM"] = null;
        $formData["ctl00_script_TSM"] = null;
        $formData["__EVENTTARGET"] = null;
        $formData["__EVENTARGUMENT"] = null;
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

        $url = "https://apptracking.northsydney.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx"
                . "?d=" . $params[0]
                . "&k=LodgementDate";
        $this->logger->info($url);

        // Accept terms
        if ($this->acceptTerms($this->getAspFormDataByUrl($url)) === false) {
            $this->logger->error("Terms could not be accepted. Stopping execution.");
            return false;
        }

        // Make sure to get XML output
        $url .= "&o=xml";

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

        $daElements = $html->find("Application");
        foreach ($daElements as $daElement) {

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->innertext());
            if ($daHtml === false) {
                continue;
            }

            $daCouncilReferenceElement = $daHtml->find("ReferenceNumber", 0);
            if ($daCouncilReferenceElement === null) {
                continue;
            }

            $daCouncilReferenceAltElement = $daHtml->find("ApplicationId", 0);
            if ($daCouncilReferenceAltElement === null) {
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->innertext());
            $daCouncilReferenceAlt = $this->cleanString($daCouncilReferenceAltElement->innertext());
            $daCouncilUrl = "https://apptracking.northsydney.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx?id=" . $daCouncilReferenceAlt;

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
            $da->setCouncilUrl($daCouncilUrl);

            $this->logger->info("");
            if ($this->saveDa($da)) {
                $this->scrapeMeta($daHtml, $da);
            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->logger->info("Done.");

    }

    public function scrapeMetaAction() {

        $this->logger->info("This council shows most required information an XML format, "
                . "so no need to separately scrape individual development application pages.");
        return false;

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
            $line2Element = $addressHtml->find("Line2", 0);

            if ($line1Element === null || $line2Element === null) {
                continue;
            }

            $line1 = $this->cleanString($line1Element->innertext());
            $line2 = $this->cleanString($line2Element->innertext());

            if (strlen($line1) === 0 || strlen($line2) === 0) {
                continue;
            }

            $address = $line1 . ", " . $line2;

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

        // For documents we do have to visit the DA-specific page
        $url = $da->getCouncilUrl();

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
            $this->logger->error("cURL error when fetching documents: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$docsHtml) {
            $this->logger->error("Could not parse HTML when fetching documents");
            return false;
        }

        $anchorElements = $docsHtml->find("a");
        foreach ($anchorElements as $anchorElement) {

            $regexPattern = '/Common\/Output\/Document\.aspx\?id=/';
            if (preg_match($regexPattern, $anchorElement->href) === 0) {
                continue;
            }

            $documentUrl = str_replace("../../", "/", $anchorElement->href);
            $documentUrl = " https://apptracking.northsydney.nsw.gov.au" . $documentUrl;

            $parentElement = $anchorElement->parent();
            if ($parentElement === null) {
                continue;
            }

            $documentNameElement = $parentElement->next_sibling();
            if ($documentNameElement === null) {
                continue;
            }

            $documentName = $this->cleanString($documentNameElement->innertext());

            $documentDateElement = $documentNameElement->next_sibling();
            if ($documentDateElement !== null) {

                $documentDateString = $this->cleanString($documentDateElement->innertext());
                $documentDate = \DateTime::createFromFormat("d/m/Y", $documentDateString);
            }

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

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

}
