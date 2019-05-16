<?php

use Aiden\Models\Das;

class CumberlandTask extends _BaseTask {

    public $council_name = "Cumberland";
    public $council_website_url = "http://www.cumberland.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "http://eplanning.cumberland.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx"
                . "?d=" . $params[0]
                . "&k=LodgementDate"
                . "&t=DA"
                . "&o=xml";

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

        $xmlDocument = new SimpleXMLElement($output);
        foreach ($xmlDocument->children() as $xmlApplication) {

            $daCouncilReference = explode(" ", $this->cleanString($xmlApplication->{"ReferenceNumber"}))[0];
            $daCouncilReferenceAlt = $this->cleanString($xmlApplication->{"ApplicationId"});
            $daCouncilUrl = "http://eplanning.cumberland.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx" . "?id=" . $daCouncilReferenceAlt . "&pprs=P";

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilUrl($daCouncilUrl);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $addressElements = $html->find("a[title=Click to display property details]");

        foreach ($addressElements as $addressElement) {

            $daAddress = $this->cleanString($addressElement->innertext());
            if ($this->saveAddress($da, $daAddress) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);

        if ($detailsElement === null) {
            $this->logger->warning("Could not find details element. Lodge date may be incorrect.");
        }
        else {

            $content = $this->cleanString($detailsElement->innertext());
            $regexPattern = '/Lodged: ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/';

            if (preg_match($regexPattern, $content, $matches) === 0) {
                $logMsg = "Could not find lodge date. Lodge date may be incorrect.";
                $this->logger->info($logMsg);
            }
            else {
                $oldLodgeDate = $da->getLodgeDate();
                $newLodgeDate = \DateTime::createFromFormat("d/m/Y", $matches[1]);
                return $this->saveLodgeDate($da, $newLodgeDate);
            }
        }

        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            $this->logger->info("Could not find details element. Estimated cost may be missing.");
            return false;
        }

        $content = $this->cleanString($detailsElement->innertext());
        $regexPattern = '/Estimated Cost of Work:\s+\$ (.+)\s/';

        if (preg_match($regexPattern, $content, $matches) === 0) {
            $this->logger->info("Could not find estimated cost. Estimated cost may be missing.");
            return false;
        }

        return $this->saveEstimatedCost($da, $matches[1]);

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;

        // <div id="edms">
        $documentsContainerElement = $html->find("div[id=edms]", 0);
        if ($documentsContainerElement === null) {
            return false;
        }

        // <div class="details">
        $detailElement = $documentsContainerElement->children(0);
        if ($detailElement) {
            return false;
        }

        // <div class="detailright"the 2nd child of <div class="detail">, the 1st child is hidden.
        $detailRightElement = $detailElement->children(1);
        if ($detailRightElement === null) {
            return false;
        }

        // <table> is the 1st childof <div class="detailright">
        $tableElement = $detailRightElement->children(0);
        if ($tableElement === null) {
            return false;
        }

        // Each of $tableElements children contains a document
        foreach ($tableElement->children() as $tableRowElement) {

            $firstTd = $tableRowElement->children(0); // Contains the URL
            $secondTd = $tableRowElement->children(1); // Contains the Name
            $thirdTd = $tableElement->children(2); // Contains the Date

            if ($firstTd === null || $secondTd === null || $thirdTd === null) {
                continue;
            }

            $anchorElement = $firstTd->children(0);
            if ($anchorElement === null) {
                continue;
            }

            // Generate Document URL
            $documentUrl = $this->cleanString($anchorElement->href);
            $documentUrl = str_replace("../../", "/", $documentUrl);
            $documentUrl = "http://eplanning.cumberland.nsw.gov.au" . $documentUrl;

            $documentName = $this->cleanString($secondTd->innertext());
            $documentDate = \DateTime::createFromFormat("r", $this->cleanString($thirdTd->innertext()));

            if ($this->saveDocument($da, $name, $url, $date)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $addedOfficers = 0;
        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);

        if ($detailsElement !== null) {

            $content = $this->cleanString($detailsElement->innertext());
            $regexPattern = '/Officer:\s+(.+)\s?/';

            if (preg_match($regexPattern, $content, $matches) !== 0) {

                $role = "Officer";
                $name = $this->cleanString($matches[1]);

                if ($this->saveParty($da, $role, $name)) {
                    $addedOfficers++;
                }
            }
        }

        return ($addedOfficers > 0);

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $peopleElement = $html->find("div[id=b_ctl00_ctMain_info_party]", 0);

        if ($peopleElement === null) {
            $this->logger->warning("Could not find people element, it may be missing");
            return false;
        }
        else {

            $rolesAndPersonsString = $this->cleanString($peopleElement->innertext());
            $rolesAndPersonsArray = explode("<br />", $rolesAndPersonsString);

            foreach ($rolesAndPersonsArray as $roleAndPersonString) {

                $roleAndPersonArray = explode("-", $roleAndPersonString);
                if (!isset($roleAndPersonArray[1])) {
                    continue;
                }

                $role = $this->cleanString($roleAndPersonArray[0]);
                $name = $this->cleanString($roleAndPersonArray[1]);

                if (strlen($name) > 0) {

                    if ($this->saveParty($da, $role, $name) === true) {
                        $addedPeople++;
                    }
                }
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {
        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
