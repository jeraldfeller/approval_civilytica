<?php

use Aiden\Models\Das;

class BankstownTask extends _BaseTask {

    public $council_name = "Bankstown";
    public $council_website_url = "http://www.bankstown.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";
    public $canterbury_bankstown_id = 34;

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "http://eplanning.bankstown.nsw.gov.au/ApplicationSearch/ApplicationSearchThroughLodgedDate"
                . "?day=" . $params[0];

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

        $resultElements = $html->find("div");
        foreach ($resultElements as $resultElement) {



            $headerElement = $resultElement->children(0);
            if (!$headerElement || $headerElement->tag !== "h4") {
                continue;
            }

            // Council URL + Reference
            $anchorElement = $headerElement->children(0);
            $daCouncilUrl = "http://eplanning.bankstown.nsw.gov.au" . $this->cleanString($anchorElement->href);
            $daCouncilReference = $this->cleanString($anchorElement->innertext());

            if($daCouncilReference != 'DA-97/2019'){
                continue;
            }

            $da = Das::exists($this->canterbury_bankstown_id, $daCouncilReference) ?: new Das();

            // Lodge date is not available on its own page, so scrape from overview.
            $regexPattern = '/Lodged :\s+([^(]*)/';
            if (preg_match($regexPattern, $resultElement->innertext(), $matches) === 1) {
                $rawDate = trim(str_replace(['</span>', '<span>'], '', $this->cleanString($matches[1])));
                $daLodgeDate = \DateTime::createFromFormat("d/m/Y", $rawDate);
            }
            else {
                $this->logger->warning("The lodge date could not be found.");
            }

            $da->setCouncilId($this->canterbury_bankstown_id);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilUrl($daCouncilUrl);
            $da->setLodgeDate($daLodgeDate);
            if ($this->saveDa($da) === true) {
                $daHtml = $this->scrapeTo($daCouncilUrl);
                $this->scrapeMeta($daHtml, $da);
                $this->logger->info("");
            }
        }

        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
//        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $thElements = $html->find("th");

        foreach ($thElements as $thElement) {

            $thText = $this->cleanString($thElement->innertext());
            if (strpos(strtolower($thText), "people") === false) {
                continue;
            }

            $valueElement = $thElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());

            $partyCombos = explode("<br />", $value);
            foreach ($partyCombos as $partyCombo) {

                if (strlen($partyCombo) === 0) {
                    continue;
                }

                $partyParts = explode(":", $partyCombo);
                if (count($partyParts) === 1) {

                    $role = "";
                    $name = $this->cleanString($partyParts[0]);

                    if ($this->saveParty($da, $role, $name) === true) {
                        $addedPeople++;
                    }
                }
                else if (count($partyParts) === 2) {

                    $role = $this->cleanString($partyParts[0]);
                    $name = $this->cleanString($partyParts[1]);

                    if ($this->saveParty($da, $role, $name) === true) {
                        $addedPeople++;
                    }
                }
            }

            break;
        }

        return ($addedPeople > 0);

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $regexPattern = '/http(s)?:\/\/webdocs.bankstown.nsw.gov.au/';
        $anchorElements = $html->find("a");

        foreach ($anchorElements as $anchorElement) {

            $documentUrl = $this->cleanString($anchorElement->href);

            if (preg_match($regexPattern, $documentUrl, $matches) === 1) {

                $documentName = $this->cleanString(strip_tags($anchorElement->innertext()));
                if ($this->saveDocument($da, $documentName, $documentUrl) === true) {
                    $addedDocuments++;
                }
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $thElements = $html->find("th");

        foreach ($thElements as $thElement) {

            $thText = $this->cleanString($thElement->innertext());
            if (strpos(strtolower($thText), "properties") === false) {
                continue;
            }

            $valueElement = $thElement->next_sibling();
            if ($valueElement === false) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $properties = explode("<br />", $value);

            foreach ($properties as $property) {

                if (strlen($property) === 0) {
                    continue;
                }

                if ($this->saveAddress($da, $property) === true) {
                    $addedAddresses++;
                }
            }

            break;
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {


        $this->logger->info("------------------- description --------------------------");

        $bElements = $html->find("b");
        foreach ($bElements as $bElement) {

            $bText = $this->cleanString($bElement->innertext());
            if (strpos(strtolower($bText), "description") === false) {
                continue;
            }

            $parentElement = $bElement->parent();
            if ($parentElement === null) {
                continue;
            }

            $valueElement = $parentElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveDescription($da, $value);
        }

        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

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
