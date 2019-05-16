<?php

use Aiden\Models\Das;

class LanecoveTask extends _BaseTask {

    public $council_name = "Lane Cove";
    public $council_website_url = "http://www.lanecove.nsw.gov.au";
    public $council_params = [];
    public $council_default_param = "";

    /**
     * Scrapes Lane Cove development applications
     */
    public function scrapeAction($params = []) {

        $url = "http://ecouncil.lanecove.nsw.gov.au/trim/advertisedDAs.aspx";

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

        $resultElements = $html->find("table");
        foreach ($resultElements as $resultElement) {

            // Some $headerElements may head multiple tables, so the 2nd table's previous sibling will be
            // another table, in that case we check until the previous sibling is either a h4, or something other 
            // than a table. If it's a h4, great, we found the header, if it's not a table, this isn't an actual resultElement.
            $headerElement = $resultElement->prev_sibling();
            while ($headerElement->tag !== "h4") {

                if ($headerElement->tag !== "table") {
                    continue 2;
                }

                $headerElement = $headerElement->prev_sibling();
            }

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($resultElement->innertext());
            if ($daHtml === false) {
                $this->logger->error("Could not parse development application HTML");
                continue;
            }

            $anchorElements = $daHtml->find("a");
            foreach ($anchorElements as $anchorElement) {

                $regexPattern = '/ecouncil\.lanecove\.nsw\.gov\.au\/trim\/DAInformation\.asp\?DANum=(.+)/';
                if (preg_match($regexPattern, $anchorElement->href, $matches) === 1) {

                    $daCouncilReference = $this->cleanString($anchorElement->innertext());
                    $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                }
            }

            $daCouncilUrl = $this->cleanString($anchorElement->href);
            $daCouncilReferenceAlt = $this->cleanString($matches[1]);

            $suburb = $this->cleanString(strip_tags($headerElement->innertext()));

            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilUrl($daCouncilUrl);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);

            $this->logger->info("");
            if ($this->saveDa($da) === true) {
                $this->scrapeMeta($daHtml, $da, $suburb);
            }
        }

        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
        $this->logger->info("Done.");

    }

    public function scrapeMetaAction() {

        $this->logger->info("This council only offers related documents on DA-specific pages, "
                . "so meta information is scraped from the scrape()-method.");
        return false;

    }

    public function scrapeMeta($html, $da, $suburb = null) {

        // Weird Phalcon bug doesn't allow us to save model twice without errors.
        $oldDaId = $da->getId();
        unset($da);
        $da = Das::findFirstById($oldDaId);
        if ($da === false) {
            $this->logger->critical("Could not find related development application in scrapeMeta()-method.");
            return false;
        }

        // Extract addresses is a core method, without it don't run.
        if (method_exists($this, "extractAddresses")) {

            $extractedAddresses = $this->extractAddresses($html, $da, $suburb);
            if ($extractedAddresses === false) {
                $this->logger->error("Did not find any addresses related to this DA, something went wrong.");
                return false;
            }
        }
        else {

            $this->logger->critical("Current class is not being able to extract address(es).");
            return false;
        }

        foreach ($this->getRequiredMethods() as $method) {
            if (method_exists($this, $method)) {
                $this->{$method}($html, $da);
            }
        }

        $da->setCrawled(true);
        if ($da->save()) {
            $this->logger->info("Finished.");
        }
        else {
            $this->logger->info("Something went wrong when trying to update crawl status ({error})", [
                "error" => print_r($da->getMessages(), true)
            ]);
        }

    }

    protected function extractAddresses($html, $da, $suburb = null): bool {

        $addedAddresses = 0;
        $tdElements = $html->find("td[class=current-development-applications]");

        foreach ($tdElements as $tdElement) {

            $address = $this->cleanString($tdElement->innertext());
            $address = $address . ", " . $suburb . " NSW";

            if ($this->saveAddress($da, $address) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $addedApplicants = 0;
        $tdElements = $html->find("td");

        foreach ($tdElements as $tdElement) {

            $tdText = $this->cleanString($tdElement->innertext());
            if (strpos(strtolower($tdText), "applicant") === false) {
                continue;
            }

            $valueElement = $tdElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            if ($this->saveParty($da, "Applicant", $value) === true) {
                $addedApplicants++;
            }
        }

        return ($addedApplicants > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $tdElements = $html->find("td");

        foreach ($tdElements as $tdElement) {

            $tdText = $this->cleanString($tdElement->innertext());
            if (strpos(strtolower($tdText), "development") === false) {
                continue;
            }

            $valueElement = $tdElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveDescription($da, $value);
        }

        return false;

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

            $regexPattern = '/(http:\/\/ecouncil\.lanecove\.nsw\.gov\.au\/TRIM\/documents_TE\/.+?\/)(.+)/';
            if (preg_match($regexPattern, $anchorElement->href, $matches) === 0) {
                continue;
            }

            $documentUrl = $this->cleanString($anchorElement->href);

            // Lane cove is still in 1995
            $fontElement = $anchorElement->parent();
            if ($fontElement === null) {
                continue;
            }

            $parentCellElement = $fontElement->parent();
            if ($parentCellElement === null) {
                continue;
            }

            $documentNameElement = $parentCellElement->prev_sibling();
            if ($documentNameElement === null) {
                continue;
            }

            $documentName = $this->cleanString(strip_tags($documentNameElement->innertext()));

            // Try to parse date, it doesn't matter if we can't.
            $documentDateElement = $documentNameElement->prev_sibling();
            if ($documentDateElement !== null) {

                $documentDateString = $this->cleanString(strip_tags($documentDateElement->innertext()));
                $documentDate = \DateTime::createFromFormat("d/m/Y g:i:s A", $documentDateString);
            }

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate) === true) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $tdElements = $html->find("td");

        foreach ($tdElements as $tdElement) {

            $tdText = $this->cleanString($tdElement->innertext());
            if (strpos(strtolower($tdText), "advertised") === false) {
                continue;
            }

            $valueElement = $tdElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $regexPattern = '/([0-9]{2}\/[0-9]{2}\/[0-9]{4})(.+)?/';

            if (preg_match($regexPattern, $value, $matches) === 0) {
                continue;
            }

            $date = \DateTime::createFromFormat("d/m/Y", $matches[1]);
            return $this->saveLodgeDate($da, $date);
        }

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        return false;

    }

}
