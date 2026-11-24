<?php
namespace php_active_record;
/* Finds the DOI of a reference from its full_reference text alone (no DOI or other identifier), using CrossRef.
   CrossRef's web form https://apps.crossref.org/SimpleTextQuery does this for pasted references, but has no API;
   its REST API does the same free-text matching: https://api.crossref.org/works?query.bibliographic=<full reference>
   Driver: update_resources/connectors/do_full_reference_search.php
   start Nov 14, 2026

   CrossRef always returns candidates, even wrong ones, so the score alone isn't trusted: each candidate is checked against
   the year, journal, volume, issue and page read from the full_reference, e.g. "1998. Brittonia,50(1): 59,1998." -> 10.2307/2807718.
   Status of each row:
     matched   : journal, volume and year (or the year next to it) agree, and the page cited is in the article's page range
                 (no year in the citation: the first author and the title must agree too)
     possible  : journal, volume and year agree, but the page can't be confirmed (e.g. CrossRef has only the first page) -> look at it
     not found : no candidate agrees; the closest candidate is still described, for review (its DOI is NOT put in the DOI column)
     skipped   : empty, too short to search, only a link (e.g. an iNaturalist, GenBank or museum record), or a GenBank sequence title
                 (e.g. "Influenza A virus (...) segment 4 hemagglutinin (HA) gene, complete cds"): not citations (Nov 21, 2026);
                 or only authors and a year (e.g. "Negredo et al. 2011"): too little to confirm a DOI (Nov 21, 2026);
                 or an identifier with no spaces (e.g. "urn:catalog:RSKM:OWLPELLET:OP-1629.0012"), a Universal Chalcidoidea Database code
                 ("XiaoJiZh2012 via Universal Chalcidoidea Database") or an Australian Faunal Directory page: not citations (Nov 21, 2026).
                 All these rules are in not_searched_note().
                 Or (only when the run asks for it, "min_year": e.g. 1900) published before that year: CrossRef has almost no DOIs for
                 works that old (Brazilian Flora, Nov 25, 2026: 1 in 443 sampled citations before 1900). A row with no year is still searched,
                 and a citation that gives its own DOI is still checked. See min_year_note().
   A 'doi' column in the input file (as in a DwCA reference.tab), when the citation has no DOI of its own, is used as a DOI the citation gives:
   CrossRef's record of it is read and checked, no search; DOI_source says 'doi column' (Nov 21, 2026).
     error     : CrossRef could not be reached; a re-run tries it again
   Every row gets a review_note in plain words, for the Data Managers' final check.
   Changes nothing else: it only writes a report TSV and a guide (_guide.txt) next to it.

   STRICT ON PURPOSE (Nov 19, 2026): a DOI is given only when the citation itself confirms it, so that 'matched' DOIs can be trusted
   without checking, and a DOI that differs from someone else's is more likely the right one. Rules that would give more DOIs but also
   wrong ones are left out on purpose: short-titled book chapters ("Azollaceae"), misspelled journal names, under 90% of a book's or
   chapter's title, CrossRef's 1st candidate when only the author and year agree. A citation without a year (FishBase) is 'matched' only
   if the first author and the title also agree.
   (Nov 24, 2026) NUMBERS ONLY: a citation with no author and no title (e.g. Brazilian Flora's "1990. Brittonia,42(3): 177,1990.") gets a DOI only when
   the journal agrees, the page cited is confirmed, CrossRef lists authors for the work and no other work is on that page; else 'not found'.
   See numbers_only_doubt().

   CHECKED AGAINST THE DATA MANAGERS' DOIs (Nov 19, 2026): the Google Sheet "doi match tests" (Sheet1), 3,561 citations, each with a DOI
   found by a Data Manager (column 'matched doi'), many by hand: https://docs.google.com/spreadsheets/d/1dtNG5qNIT_Y7E2JlR8QCboC1YWn6IwabMV9TQ2fJL4w/edit?gid=0
     - the tool gave a DOI for 3,243; 3,202 the same as the DM's, 36 another DOI of the same work (or the journal article vs. its book
       reprint), 4 that fit the citation better than the DM's, 1 wrong (a 'possible');
     - 'matched' 2,682: all usable; 'possible' 561: 560 usable; 'not found' 318 (in 139 of them the closest CrossRef candidate, shown in
       crossref_DOI, was the DM's DOI, but the citation couldn't confirm it);
     - 16 of the DM's DOIs don't fit their citations (don't exist, not DOIs, other works, e.g. another Mammalian Species number):
       the tool rightly gives none.
     - CrossRef's candidates alone are not trusted. Of the 318 'not found' rows, the DM's DOI was CrossRef's 1st candidate in 200, its 2nd-5th
       in 32, not among its 5 in 86 (48 of them registered with a year outside the cited year +-1, e.g. digital reprints: the search's year
       filter leaves them out). Taking CrossRef's 1st candidate as the DOI would add 200 of the DM's DOIs and 118 others; at a score of 100
       or more, 89 and 10: never better than about 90%. So it is never filled in. When the closest candidate (crossref_DOI) scores HINT_SCORE
       (80) or more, the review_note says "Worth a look" (candidate_hint): 102 of 114 were the DM's DOI. In 6 rows the DM's DOI that doesn't
       fit its citation was CrossRef's 1st candidate.
   Every change was also run on 540 other citations (FishBase, reference.tab, the style samples) and every changed row read: no wrong
   DOI added. The cases are in FullReferenceSearchAPI_tests.json: 'dm-sheet-<identifier>' = that row of the sheet.
   Report for the Data Managers: Full_Reference_Search_vs_DM_DOIs_report.md (with our_tool_vs_DM_DOIs.tsv, row by row).
----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class FullReferenceSearchAPI extends GenerateTB_Files_Functions
{
    const OUTPUT_COLUMNS = array('identifier', 'full_reference', 'searched_text', 'text_changes', 'status', 'DOI', 'DOI_URL', 'DOI_source', 'review_note',
                                 'parsed_author', 'parsed_year', 'parsed_journal', 'parsed_volume', 'parsed_issue', 'parsed_page',
                                 'crossref_DOI', 'crossref_score', 'crossref_type', 'crossref_year', 'crossref_journal',
                                 'crossref_volume', 'crossref_issue', 'crossref_page', 'crossref_title', 'crossref_authors', 'checks');
    const MIN_LENGTH = 10; // shorter full_reference values aren't searched
    const GENBANK_LINK_AT_END = '~\s+https?://(?:www\.)?ncbi\.nlm\.nih\.gov/nuccore/\s?[A-Za-z0-9_.]+/?\s*$~i'; // (Nov 23, 2026) see TEXT_CHANGES 'genbank_link'
    const BARE_LINK = '~^(https?://|www\.)\S+$~i'; // (Nov 21, 2026) a full_reference that is only a link, e.g. http://www.ncbi.nlm.nih.gov/nuccore/109628396, https://www.inaturalist.org/observations/...:
                                                   // not searched (CrossRef can't match a link; none of 6,765 did). A link with its own DOI (doi.org/10...) is still used.
    const GENBANK_TITLE = '/(,? (complete|partial) (cds|sequence|genome|chromosome)|segment \d+,? (complete |partial |cRNA |genomic )?sequence|genome assembly, segment: ?\d+|, whole genome shotgun sequence)\.?$/';
        // (Nov 21, 2026) a GenBank sequence title, not a citation, e.g. "Influenza A virus (A/chicken/Jiangsu/J2323/2017(H9N2)) segment 4 hemagglutinin (HA) gene, complete cds",
        // "Influenza A virus genome assembly, segment: 6": not searched. A title that ends otherwise (e.g. "..., partial cds, strain: BRbv611") is still searched.
        // Only the END of the text counts, so a paper's title about sequences ("... internal transcribed spacer sequence analysis ...") is still searched.
        // On the 3.5M-row reference.tab (Nov 21, 2026): 1,335,149 rows caught, all with a GenBank (nuccore) uri; 0 of the 224,107 other citations.
    const AUTHOR_YEAR_ONLY = '/^\p{Lu}[^0-9.;:()]{0,80}?( et al\.?| (&|and) [^0-9;:()]{1,60})?,? \(?(1[5-9]|20)\d\d[a-z]?\)?\.?$/u';
        // (Nov 21, 2026) only authors and a year, e.g. "Negredo et al. 2011", "Trainer & Knowlton 1968", "Carpenter (1981)": no title, journal, volume or
        // page, so no DOI can be confirmed (STRICT ON PURPOSE): not searched. Of 1,202 such citations searched before, none was matched or possible.
        // 5,577 rows of the 3.5M-row reference.tab. "Smith, J. 2001" (initials) or anything with a title, journal or page is still searched.
    const GENBANK_TITLE_FORMS = '/^UNVERIFIED: |^Influenza [ABCD] virus \(|, (complete|partial) cds, (strain|isolate|clone)\b|\b(partial )?(viral )?cRNA for\b|\bgenomic RNA, (strain|isolate|clone)\b|\bpartial mRNA for\b|\bgene for [^,]{1,80}, (complete|partial) cds\b/';
        // (Nov 21, 2026) more GenBank title forms, anywhere in the text: "Rabies virus G gene for glycoprotein, complete cds, strain: RV/R4A.PHL/2006/Z-06-361",
        // "Influenza A virus (A/equine/...) partial viral cRNA for hemagglutinin precursor (HA gene), isolate 307476, clone 42", "UNVERIFIED: Porcine epidemic
        // diarrhea virus isolate VN/ND-2/2018 sequence". 13,679 texts of the big reference.tab, all with a GenBank uri; none looks like a citation.
    const NO_SPACE = '/^\S+$/u';
        // (Nov 21, 2026) no space at all: an identifier or a code, not a citation, e.g. "urn:catalog:RSKM:OWLPELLET:OP-1629.0012" (82,675 texts),
        // "88f93a7c-d8e1-11e2-99a2-0026552be7ea", "FB_REF:1234". Checked after the DOI rules, so "doi:10.1656/058.011.0312" still gets its DOI.
    const CHALCID_DB_CODE = '/^\S+ via Universal Chalcidoidea Database$/';
        // (Nov 21, 2026) e.g. "XiaoJiZh2012 via Universal Chalcidoidea Database": a code for a reference in that database, not the citation (12,509 texts)
    // (Nov 23, 2026) FishBase's web pages: OBIS distribution maps and FAO-FIGIS species fact sheets (no DOI), e.g.
    //   "Ocean Biogeographic Information System. Data Extent Map: OBIS Stored Distribution-Abalistes stellaris. (data sourced from ...)",
    //   "FAO-FIGIS. A world overview of species of interest to fisheries. Chapter: Engraulis ringens. Retrieved on 30 May 2005, from www.fao.org/figis/..."
    //   (on reference_fishbase.tab: 496 rows; none in GloBI's reference.tab)
    const FISHBASE_WEB_PAGE = '/^Ocean Biogeographic Information System\. |\bA world overview of species of interest to fisheries\. Chapter: /u';
    // (Nov 23, 2026) a short citation: first author, year, journal and volume only, no title or page, e.g. "Brodie SJ, 1998, J Virol, 72",
    //   "Onuma M, 1992, J Acquir Immune Defic Syndr (1988), 5": no article can be confirmed from it (on GloBI's reference.tab: 18,080 rows, all 'not found'
    //   in the 1,083 searched). No /u: some of these names have broken UTF-8 (e.g. "Erd\xEFlyi K"), which /u would never match.
    //   (Nov 23, 2026, widened) also a part or supplement, a double volume, or no volume: "77 ( Pt 4)", "60 (Pt 5)", "39 Pt 2", "10 Suppl 4", "129 Suppl",
    //   "141 Spec No", "454-455", "Van Der Maaten MJ, 1975, Bibl Haematol," (661 more rows; the 108 searched were all 'not found')
    const SHORT_CITATION = '/^[^,]{2,60}, (?:1[5-9]|20)\d\d, [^,]{2,80},(?: [0-9A-Za-z]{1,6}(?:-\d{1,6})?(?: ?\( ?Pt [0-9A-Za-z]{1,3} ?\)| Pt [0-9A-Za-z]{1,3}| Suppl(?: [0-9A-Za-z]{1,3})?| Spec No)?)?$/';
    const AFD_PAGE = '/^\S+ \d{4}\. Australian Faunal Directory\./';
        // (Nov 21, 2026) e.g. "ABRS 2009. Australian Faunal Directory. Didymocylindrus simplex. ... Accessed at on 11 Mar 2026.": a database page, no DOI (4,396 texts)
    const SECONDS_PER_NEW = 3; // about 3 seconds per new reference (CrossRef's answer + PAUSE_SECONDS), for estimates; same as the web page's
    const PROGRESS_EVERY = 50; // a progress line ("N rows done ...") every this many rows; the web page reads it
    /* PACE: calls are one at a time, with a PAUSE_SECONDS pause after each one (about 1.8-1.9 s from one call's start to the next): thousands of
       calls without a single HTTP 429. Also never faster than CrossRef's own x-rate-limit-* headers (interval/limit + 10%), should they ever ask
       for a slower pace than that. Cached answers need no call and no wait.
       (Nov 21, 2026) Tried and given up: no pause, calls at least 1.0 / 1.2 / 1.5 / 1.7 s apart. The headers say 3 per second (polite pool),
       but searches got 429 at all of them: 1 in 12 calls at 1.0 and 1.2 s, after about 170 calls at 1.5 s, after about 15 calls at 1.7 s
       (6 minutes after an earlier 429: CrossRef seems to count over several minutes). The real limit is about where this pause already is. */
    const PAUSE_SECONDS = 1;    // the pause after every CrossRef (and DataCite) call
    const THROTTLE_LIMIT = 5;   // after this many "429 too many requests" / "503 busy" answers in a row (with waits of 1, 2, 4, 8 minutes), the search stops
    const KEEP_REPORTS = 20;  // only the newest 20 reports (each with its _guide.txt) are kept in Reference_maintenance/; older ones are deleted
    const REPORT_RE = '/^[A-Za-z0-9._-]+_DOIs_(\d{8}_\d{6})\.tsv$/'; // e.g. reference_DOIs_20261114_013451.tsv; the time it was made sorts them
    // CrossRef record types that are a whole issue/volume/journal or a part of an article, not an article: never the answer
    /* The author and title checks (added Nov 17, 2026), all in one place so they can be turned off, or removed, if the Data Managers say so:
         - turn off for one run: "author_title_checks": false in the parameters
         - turn off for good: set this constant to false
         - remove: delete this constant, author_title_check(), crossref_authors(), the block marked AUTHOR/TITLE in find_DOI(), and the
           AUTHOR/TITLE lines in review_note(), agreed_text() and write_guide(); the parsed_author and crossref_authors columns go too.
       What they do: the first author's surname read from the citation is compared with CrossRef's authors, and CrossRef's title words with the citation.
         - they break ties between candidates whose numbers agree equally
         - a 'matched' candidate whose authors clearly differ becomes 'possible', with a note
         - only when BOTH disagree (the authors differ and under 30% of CrossRef's title is in the citation) is a candidate 'not found':
           one alone is never enough, as CrossRef's author and title data for old works is often incomplete or garbled */
    const AUTHOR_TITLE_CHECKS = true;
    /* DATACITE: the second search, in DataCite (https://api.datacite.org), for rows CrossRef can't confirm and where no volume or pages could be read:
       datasets (PANGAEA, GBIF, Zenodo), reports, theses, books. DataCite registers DOIs that CrossRef doesn't have, e.g. 10.1594/PANGAEA.692046.
       Added Nov 18, 2026; all in one place so it can be turned off or removed:
         - turn off for one run: "datacite_search": false in the parameters; for good: set this constant to false
         - remove: delete this constant, datacite_route(), search_datacite(), datacite_work(), the DATACITE lines in find_DOI(), doi_given(),
           __construct(), run_tests() and write_guide()
       It needs the author and title checks (they confirm a DataCite record), so it's off when they are off. A DataCite DOI is at most 'possible'. */
    const DATACITE_SEARCH = true;
    const HINT_SCORE = 80; // (Nov 19, 2026) a 'not found' row whose closest CrossRef candidate scores this or more gets a "worth a look" hint (candidate_hint)
    /* (Nov 22, 2026) AUTHORS IN THE SEARCH: the search asks CrossRef for 'author' too, so a candidate's authors come with the search answer, at no extra call.
       Before, each candidate that passed the other checks cost one more call (https://api.crossref.org/works/<DOI>) for its authors: with mostly matched
       rows that was 2 calls a row, about 4 s instead of 2 (in build #18 of the whole reference.tab, 763 of 1,824 calls in an hour).
       A search answered before this change (cached under the URL without 'author') is still read from the cache, and its candidates' authors still come
       from their own records (cached too), as before: nothing searched before is asked again. */
    /* CACHE (Nov 22, 2026): every answer is kept for good, so nothing is asked twice: CrossRef's and DataCite's searches and records, empty answers too,
       and a DOI they don't have (HTTP 404), kept under MISSING_KEY + the URL. Only failures that may pass are not kept: no connection, 5xx, 429/503;
       such a row is marked 'error' and asked again on the next run. A BibTeX entry is rewritten here (no lookup); its search is cached like any other. */
    const MISSING_KEY = 'missing:';
    /* SHARED PACE (Nov 22, 2026): every process that calls CrossRef or DataCite -- a search in Jenkins, the web page, analyze_MoF -- keeps ONE pace
       together, through a small file per service in Reference_maintenance/ (.crossref_pace.json, .datacite_pace.json; both Docker containers see it):
         - a call is made only while holding that file's lock, so there is never more than one call at a time, whichever process makes it
         - the file says when the next call may start: PAUSE_SECONDS after the last call ended, or after a 429/503 wait, for every process
       Before, each process kept the pace on its own: two at once (build #2 of the whole reference.tab + analyze_MoF on AmphibiaWeb) doubled it,
       and CrossRef answered 429. Now a second process just takes turns; neither has to wait for the other to finish. */
    const PACE_WAIT_NOTE_SECONDS = 15; // a wait for the shared pace longer than this is printed, with the reason
    const MAX_AUTHOR_LOOKUPS = 3; // per row, for a search answered before AUTHORS IN THE SEARCH only: each candidate that passes the checks costs one call for its authors (cached)
    const NOT_AN_ARTICLE = array('journal-issue', 'journal-volume', 'journal', 'component', 'proceedings', 'book-series', 'book-set', 'report-series');
    // (Nov 19, 2026) titles of records that are a journal's or book's furniture, not a work: never the answer
    const NOT_A_WORK_TITLE = '/^(?:(?:table of )?contents?|content ?snapshots?|front matter|back matter|front cover|back cover|cover|issue information|editorial board|masthead|index|title page|preface|errat(?:a|um)|corrigend(?:a|um))\s*$|\bTable of Contents\s*$/i';
    // (Nov 19, 2026) a figure or table of an article, copied to Zenodo (e.g. "FIGURE 6 in Evolution in karst massifs: ..."): never the article itself
    // (Nov 19, 2026) a citation of a dataset as a dataset: DataCite is searched first
    const DATASET_CITATION = '/\[\s*data\s*-?\s*set\s*\]|\bData from:|\b(?:Dryad|PANGAEA|figshare|Zenodo)\b.{0,200}\bdata\s*-?\s*set\b|\bdata\s*-?\s*set\b.{0,200}\b(?:Dryad|PANGAEA|figshare|Zenodo)\b/i';
    // (Nov 19, 2026) words that show a citation is of a preprint or discussion paper
    const PREPRINT_CITED = '/\b(?:bioRxiv|medRxiv|arXiv|EcoEvoRxiv|EarthArXiv|Preprints?|Research Square|SSRN|Authorea|Discussions?|Discuss\.)\b/i';
    /* (Nov 19, 2026) journals renamed (or cited by a later name): the name in the citation => CrossRef's journal. Only facts about these journals;
       the volume, page and year must still agree. e.g. "Quart. J. Microsc. Sci., 102, 257-272" is "Journal of Cell Science" volume "S3-102". */
    const RENAMED_JOURNALS = array(
        '/Quart(?:erly)?\.?\s*J(?:ourn(?:al)?)?\.?\s*(?:of\s*)?Micros(?:c(?:op(?:ical)?)?)?\.?\s*Sci/i'                                    => '/^Journal of Cell Science$/i',
        '/Bull(?:etin)?\.?\s*(?:of\s*the\s*)?Ja?p(?:an)?(?:ese)?\.?\s*Soc(?:iety)?\.?\s*(?:of\s*)?Sci(?:entific)?\.?\s*Fish/i'            => '/^Nippon Suisan Gakkaishi$/i',
        '/\bAnim(?:al)?\.?\s*Prod(?:uction)?\b/i'                                                                                     => '/^Animal Science$/i',
        '/\bZuchthygiene\b/i'                                                                                                          => '/^Reproduction in Domestic Animals$/i',
        '/Verh(?:andlungen)?\.?\s*(?:des\s*)?Int(?:ernat(?:ional)?)?\.?\s*(?:en\s*)?Verein(?:igung)?\.?\s*(?:f\S*r\s*)?(?:theoret\S*\s*(?:und|u\.)\s*angew\S*\s*)?Limnol/i' => '/^SIL Proceedings/i',
        '/Soil\s*Sci(?:ence)?\.?\s*Soc(?:iety)?\.?\s*(?:of\s*)?Am(?:er(?:ica)?)?\.?\s*Proc/i'                                          => '/^Soil Science Society of America Journal$/i',
        '/J(?:ournal)?\.?\s*(?:of\s*)?Eukaryot(?:ic)?\.?\s*Microbiol/i'                                                                => '/^The Journal of Protozoology$/i');
    const FIGURE_COPY_TITLE = '/^(?:figures?|figs?\.?|tables?|plates?)\s*[\dIVX]+[a-z]?\b.{0,20}?\b(?:in|from)\b/i';
    function __construct($param) {
        $this->param = $param; print_r($param);
        $this->pace = array(); // PACE: per service, the gap between call starts and whether CrossRef asked us to slow down
        $this->author_title_checks = array_key_exists('author_title_checks', (array) $param) ? (bool) $param['author_title_checks'] : self::AUTHOR_TITLE_CHECKS;
        $this->datacite_search = $this->author_title_checks && (array_key_exists('datacite_search', (array) $param) ? (bool) $param['datacite_search'] : self::DATACITE_SEARCH); // DATACITE
        $this->min_year = self::min_year_param($param); // MIN YEAR: 0 = off (the default); false = not a valid year (start() says so)
        $this->min_year_skips = 0; // MIN YEAR: rows skipped by it, for the guide
        $this->work_dir = CONTENT_RESOURCE_LOCAL_PATH . "Reference_maintenance/";
        if(!is_dir($this->work_dir)) @mkdir($this->work_dir, 0777, true);
        // CrossRef's "polite pool" (twice the rate limit) needs an email: optional constant CROSSREF_MAILTO in config/environments/<env>.php (not in GitHub).
        // It goes in the User-Agent, not the URL, so the cache doesn't depend on it.
        $user_agent = 'EOL-connector/1.0' . (defined('CROSSREF_MAILTO') && CROSSREF_MAILTO ? ' (mailto:' . CROSSREF_MAILTO . ')' : '');
        $this->crossref_works_url = "https://api.crossref.org/works"; // tests only may point it elsewhere
        // the cache (read with lookup_with_cache, written by call_crossref); the calls themselves are made by call_crossref(), which sees HTTP 429/503
        $this->crossref_options = array('resource_id' => 'crossref', 'expire_seconds' => false, // never expires: the same full_reference is never asked twice
                                        'timeout' => 60,
                                        'user_agent' => $user_agent,
                                        'validation_regex' => '"status":"ok"', // a failed call isn't kept as a valid cache
                                        'name' => 'CrossRef');
        // DATACITE: DataCite's answers, cached the same way in their own folder; DataCite also asks for a User-Agent with a mailto
        $this->datacite_url = "https://api.datacite.org/dois";
        $this->datacite_options = array_merge($this->crossref_options, array('resource_id' => 'datacite', 'validation_regex' => '"data":', 'name' => 'DataCite'));
    }
    function start()
    {   // Runs the task; also returns its result.
        $task = $this->param['task'] ?? '';
        if($task == 'run_tests') return $this->run_tests(); // no lock: it writes no report; the page doesn't start it while a search runs
        if($task != 'search_DOIs_for_full_references') { echo "\nERROR: Unknown task [$task]. Available: search_DOIs_for_full_references, run_tests\n"; return null; }
        // (Nov 21, 2026) the user is never left guessing: a wrong file is said at once (not after waiting for another search), and a wait is announced
        if(!self::input_file_ok((string) ($this->param['file'] ?? ''))) return false;
        if($this->min_year === false) { echo "\nERROR: \"min_year\" must be a year, e.g. \"min_year\": 1900 (rows published before it are skipped). Got: [" . json_encode($this->param['min_year']) . "]. Nothing was searched.\n"; return false; }
        // one run at a time (also across the web and Jenkins containers: same lock file): a second run waits here until the first one is done
        $lock = @fopen($this->work_dir . ".full_reference_search.lock", 'c');
        if($lock) { @chmod($this->work_dir . ".full_reference_search.lock", 0666); $this->wait_for_lock($lock); }
        @file_put_contents($this->running_file(), json_encode(array('file' => (string) $this->param['file'], 'started' => date("Y-m-d H:i:s"),
                                                                 'where' => self::where_running(), 'pid' => getmypid()), JSON_UNESCAPED_SLASHES)); // who holds the lock, for a waiting run and the web page
        @chmod($this->running_file(), 0666);
        $result = $this->search_DOIs_for_full_references();
        @unlink($this->running_file());
        if($lock) { flock($lock, LOCK_UN); fclose($lock); }
        return $result;
    }
    function running_file() { return $this->work_dir . ".full_reference_search.running.json"; } // the search holding the lock: file, started, where, pid
    static function where_running() { return is_dir('/var/jenkins_home') ? 'Jenkins' : 'the web container (web page or command line)'; }
    private static function input_file_ok($file)
    {   // before any wait: a missing or unreadable file is said at once, with the usual cause inside Docker
        if($file === '') { echo "\nERROR: No \"file\" given. Nothing was searched.\n  e.g. \"file\": \"" . CONTENT_RESOURCE_LOCAL_PATH . "reference.tab\"\n"; return false; }
        if(is_file($file) && is_readable($file)) return true;
        echo "\nERROR: File not found or not readable: [$file]. Nothing was searched.\n";
        if(!preg_match('#^/(Volumes|var/www/html|extra)/#', $file))
            echo "  This script runs inside Docker, which sees only /Volumes/..., /var/www/html/... and /extra/... -- not /Users/... or ~/Desktop.\n"
               . "  Copy the file under /Volumes, e.g. /Volumes/Crucial_2TB/eol_resources/, and give that path.\n";
        return false;
    }
    private function wait_for_lock($lock)
    {   // takes the one-search-at-a-time lock; if another search holds it, says which one and waits, with a line every 10 minutes
        if(flock($lock, LOCK_EX | LOCK_NB)) return;
        $who = @json_decode((string) @file_get_contents($this->running_file()), true);
        echo "\nWAITING: another search is running" . (is_array($who) ? ": [" . basename((string) @$who['file']) . "], started " . @$who['started'] . ", in " . @$who['where'] : '') . ".\n"
           . "  Only one search runs at a time (CrossRef is asked politely, one call at a time). This one starts by itself as soon as that one ends.\n"
           . "  To stop waiting: Ctrl-C (or [Abort] in Jenkins). To stop the other search: [Stop the search] on the web page if it was started there,\n"
           . "  else Ctrl-C / [Abort] where it runs.\n";
        $t0 = time(); $next = 600;
        while(!flock($lock, LOCK_EX | LOCK_NB)) {
            sleep(5);
            if(time() - $t0 >= $next) { echo "  still waiting for the other search (" . round((time() - $t0) / 60) . " minutes so far)\n"; $next += 600; }
        }
        echo "The other search has ended (waited " . round((time() - $t0) / 60) . " minutes): this one starts now.\n";
    }
    private function search_DOIs_for_full_references()
    {   /* $this->param:
            'file'  => required, TSV whose header has 'identifier' and 'full_reference', e.g. reference.tab
            'limit' => optional, search only this many rows (e.g. 200 for a test)
            'start' => optional, begin at this data row (1 = the first row after the header)
            'min_year' => optional, e.g. 1900: rows published before it are 'skipped', not searched (see min_year_note())
        Writes <file name>_DOIs_<YYYYmmdd_HHMMSS>.tsv in Reference_maintenance/, row by row (a stopped run keeps what it did),
        and <same>_guide.txt explaining it for the Data Managers. Returns array('file' => output path, 'counts' => status => n), or false. */
        $file = (string) ($this->param['file'] ?? '');
        if(!is_file($file)) { echo "\nERROR: No file [$file]. Give one, e.g. \"file\": \"" . CONTENT_RESOURCE_LOCAL_PATH . "reference.tab\"\n"; return false; }
        $limit = (int) ($this->param['limit'] ?? 0);
        $start = max(1, (int) ($this->param['start'] ?? 1));
        if(!($in = fopen($file, 'r'))) { echo "\nERROR: Cannot read [$file]\n"; return false; }
        $header = explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($in)), "\r\n"));
        $col_id = array_search('identifier', $header); $col_ref = array_search('full_reference', $header); $col_doi = array_search('doi', $header); // 'doi': optional
        if($col_id === false || $col_ref === false) { fclose($in); echo "\nERROR: The header must have 'identifier' and 'full_reference'. Got: [" . implode(', ', $header) . "]\n"; return false; }

        $out_file = $this->work_dir . pathinfo($file, PATHINFO_FILENAME) . "_DOIs_" . date("Ymd_His") . ".tsv";
        $out = fopen($out_file, 'w');
        fwrite($out, implode("\t", self::OUTPUT_COLUMNS) . "\n");
        echo "\nInput: [$file]\nOutput: [$out_file]\nRows: from " . $start . ($limit ? ", at most $limit" : ', to the end') . "\n";
        if($this->min_year) echo "Year limit (\"min_year\": {$this->min_year}): rows published before {$this->min_year} are skipped, not searched; rows with no year are searched.\n";
        $this->keep_latest_reports(); // here, not at the end, so stopped searches are counted too; the new report is one of the 20
        if(($this->param['estimate'] ?? true) !== false) { // the web page shows its own estimate before [Proceed], so it passes "estimate": false
            echo "Counting the rows searched before (in the cache), for the estimate...\n";
            if($n = $this->count_rows_to_search($file, $start, $limit)) echo self::estimate_text($n) . "\n";
        }
        echo "Searching: a progress line every " . self::PROGRESS_EVERY . " rows (about 2 to 3 minutes when the rows are new; instant when they were searched before).\n";
        $counts = array('matched' => 0, 'possible' => 0, 'not found' => 0, 'skipped' => 0, 'error' => 0);
        $changed = array_fill_keys(array_keys(self::TEXT_CHANGES), 0); // rows changed by each pre-processing rule, for the guide
        $time = time(); $row_no = 0; $done = 0;
        while(($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if(trim($line) === '') continue;
            $row_no++;
            if($row_no < $start) continue;
            if($limit && $done >= $limit) break;
            $f = explode("\t", $line);
            $identifier = (string) ($f[$col_id] ?? ''); $full_reference = (string) ($f[$col_ref] ?? ''); // as in the input file; find_DOI() cleans it
            $r = $this->find_DOI($full_reference, $col_doi !== false ? (string) ($f[$col_doi] ?? '') : '');
            if($r['status'] == 'stop') { $stopped_at = $row_no; break; }
            $counts[$r['status']]++; $done++;
            foreach($r['change_codes'] as $code) $changed[$code]++;
            fwrite($out, self::report_line($identifier, $full_reference, $r));
            if($done % self::PROGRESS_EVERY == 0) { fflush($out); echo "  $done rows done (row $row_no): " . self::counts_str($counts) . "\n"; }
        }
        fclose($in); fclose($out);
        if(isset($stopped_at)) { // CrossRef kept answering 429/503: stop politely; the report keeps the rows done so far
            $guide = $this->write_guide($out_file, $file, $start, $stopped_at - 1, $done, $counts, $changed);
            echo "\nERROR: CrossRef kept asking us to slow down (HTTP 429/503, " . self::THROTTLE_LIMIT . " times in a row), so the search stopped at row $stopped_at"
               . " after $done rows (" . self::counts_str($counts) . ").\nReport (the rows done so far): [$out_file]\nGuide for the Data Managers: [$guide]\n"
               . "To go on later, run again with \"start\": $stopped_at (and the same file).\n";
            return array('file' => $out_file, 'guide' => $guide, 'counts' => $counts, 'stopped_at' => $stopped_at);
        }
        $guide = $this->write_guide($out_file, $file, $start, $row_no, $done, $counts, $changed);
        echo "\nDone: $done rows in " . gmdate("H:i:s", time() - $time) . " -- " . self::counts_str($counts) . "\nReport: [$out_file]\nGuide for the Data Managers: [$guide]\n";
        return array('file' => $out_file, 'guide' => $guide, 'counts' => $counts);
    }
    function run_tests()
    {   /* The known cases in FullReferenceSearchAPI_tests.json (problems fixed before, CrossRef's own sample citations): each must still give the
           expected result, so a change that breaks a known fact shows at once. Writes no report; saves the results in
           Reference_maintenance/full_reference_search_tests_last.json (the web page shows them) and returns them. Uses the same cache as a search. */
        $file = dirname(__FILE__) . "/FullReferenceSearchAPI_tests.json";
        $d = @json_decode((string) @file_get_contents($file), true);
        if(!is_array($d) || empty($d['cases'])) { echo "\nERROR: No test cases in [$file]\n"; return false; }
        $res = array('when' => date("Y-m-d H:i:s"), 'author_title_checks' => $this->author_title_checks, 'datacite_search' => $this->datacite_search, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'cases' => array());
        foreach($d['cases'] as $t) {
            $e = $t['expect']; $r = array('id' => $t['id'], 'source' => $t['source'], 'full_reference' => $t['full_reference'], 'why' => $t['why'], 'problems' => array());
            $off = array('author_title_checks' => !$this->author_title_checks ? 'author and title checks are off' : '',
                         'datacite_search' => !$this->datacite_search ? 'the DataCite search is off' : ''); // DATACITE
            if(($why_off = $off[$e['requires'] ?? ''] ?? '') !== '') { $r['result'] = 'skipped'; $r['got'] = $why_off; $res['skipped']++; $res['cases'][] = $r; continue; }
            $keep = $this->min_year; if(isset($t['min_year'])) $this->min_year = (int) $t['min_year']; // MIN YEAR: a case can set the run's "min_year"
            $got = $this->find_DOI($t['full_reference'], $t['doi'] ?? '');
            $this->min_year = $keep;
            if($got['status'] == 'stop') { echo "\nCrossRef keeps asking us to slow down: the tests stopped. Try again later.\n"; $res['stopped'] = true; break; }
            foreach($e as $k => $want) {
                if($k == 'requires') continue;
                if(str_ends_with($k, '_contains')) {
                    $col = substr($k, 0, -9); $col = $col == 'note' ? 'review_note' : $col;
                    if(!str_contains((string) ($got[$col] ?? ''), $want)) $r['problems'][] = "$col should contain \"$want\"";
                }
                elseif(strcasecmp((string) ($got[$k] ?? ''), (string) $want) !== 0) $r['problems'][] = "$k: expected \"$want\", got \"" . ($got[$k] ?? '') . "\"";
            }
            $r['result'] = $r['problems'] ? 'FAIL' : 'PASS'; $res[$r['problems'] ? 'failed' : 'passed']++;
            $r['got'] = $got['status'] . ($got['DOI'] !== '' ? " " . $got['DOI'] : '');
            $r['expected'] = $e['status'] . (!empty($e['DOI']) ? " " . $e['DOI'] : '');
            $r['review_note'] = $got['review_note'];
            $res['cases'][] = $r;
            echo str_pad($r['result'], 5) . " " . $t['id'] . ($r['problems'] ? "\n      " . implode("\n      ", $r['problems']) : '') . "\n";
        }
        file_put_contents($this->work_dir . "full_reference_search_tests_last.json", json_encode($res));
        echo "\nTests: {$res['passed']} passed, {$res['failed']} failed, {$res['skipped']} skipped (" . count($d['cases']) . " cases).\n";
        return $res;
    }
    private static function report_line($identifier, $full_reference, $r)
    {   // one report row (OUTPUT_COLUMNS) for a find_DOI() result
        $row = array_merge(array('identifier' => $identifier, 'full_reference' => $full_reference), $r);
        return implode("\t", array_map(fn($c) => str_replace(array("\t", "\r", "\n"), ' ', (string) ($row[$c] ?? '')), self::OUTPUT_COLUMNS)) . "\n";
    }
    /* The same report and guide, for another program that calls find_DOI() row by row (e.g. AnalyzeMoF_API, for a DwCA's reference.tab):
         $frs->report_open('13318109_AmphibiaWeb_finale_reference');            -> Reference_maintenance/<name>_DOIs_<YYYYmmdd_HHMMSS>.tsv
         $r = $frs->find_DOI($full_reference); $frs->report_row($identifier, $full_reference, $r);   (a 'stop' result isn't written)
         $frs->report_close('[resource] reference.tab');                       -> writes <same>_guide.txt; returns array('file', 'guide', 'counts')
       Same columns, same guide, and counted with the tool's own reports (only the newest KEEP_REPORTS are kept). Nov 20, 2026 */
    function report_open($name)
    {   $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $name);
        $file = $this->work_dir . $name . "_DOIs_" . date("Ymd_His") . ".tsv";
        if(!($out = fopen($file, 'w'))) { echo "\nERROR: Cannot write [$file]\n"; return false; }
        fwrite($out, implode("\t", self::OUTPUT_COLUMNS) . "\n");
        $this->keep_latest_reports();
        $this->rep = array('file' => $file, 'out' => $out, 'rows' => 0, 'time' => time(),
                           'counts' => array('matched' => 0, 'possible' => 0, 'not found' => 0, 'skipped' => 0, 'error' => 0),
                           'changed' => array_fill_keys(array_keys(self::TEXT_CHANGES), 0));
        echo "\nFull Reference Search report: [$file]\n";
        return $file;
    }
    function report_row($identifier, $full_reference, $r)
    {   if(empty($this->rep) || ($r['status'] ?? '') == 'stop' || !isset($this->rep['counts'][$r['status'] ?? ''])) return;
        $this->rep['counts'][$r['status']]++; $this->rep['rows']++;
        foreach($r['change_codes'] ?? array() as $code) if(isset($this->rep['changed'][$code])) $this->rep['changed'][$code]++;
        fwrite($this->rep['out'], self::report_line($identifier, $full_reference, $r));
        if($this->rep['rows'] % self::PROGRESS_EVERY == 0) fflush($this->rep['out']);
    }
    function report_close($input_label)
    {   if(empty($this->rep)) return false;
        fclose($this->rep['out']);
        $n = $this->rep['rows'];
        $guide = $this->write_guide($this->rep['file'], (string) $input_label, 1, $n, $n, $this->rep['counts'], $this->rep['changed']);
        echo "\nFull Reference Search: $n rows in " . gmdate("H:i:s", time() - $this->rep['time']) . " -- " . self::counts_str($this->rep['counts'])
           . "\nReport: [{$this->rep['file']}]\nGuide for the Data Managers: [$guide]\n";
        $ret = array('file' => $this->rep['file'], 'guide' => $guide, 'counts' => $this->rep['counts']);
        unset($this->rep);
        return $ret;
    }
    private function keep_latest_reports()
    {   // deletes all but the newest KEEP_REPORTS reports (by the time in their name), each with its _guide.txt
        $reports = array();
        foreach(scandir($this->work_dir) ?: array() as $f) if(preg_match(self::REPORT_RE, $f, $m) && is_file($this->work_dir . $f)) $reports[$f] = $m[1];
        asort($reports, SORT_STRING); // oldest first
        $old = array_slice(array_keys($reports), 0, max(0, count($reports) - self::KEEP_REPORTS));
        $n = 0;
        foreach($old as $f) {
            if(unlink($this->work_dir . $f)) $n++;
            $guide = preg_replace('/\.tsv$/', '_guide.txt', $f);
            if(is_file($this->work_dir . $guide)) unlink($this->work_dir . $guide);
        }
        if($n) echo "Removed $n old report(s) like [" . $old[0] . "], with their guides: only the newest " . self::KEEP_REPORTS . " are kept.\n";
    }
    private static function counts_str($counts)
    {   return implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($counts), $counts));
    }
    function find_DOI($full_reference, $doi_column = '')
    {   // Searches CrossRef for one full_reference and checks its candidates. Returns the OUTPUT_COLUMNS from 'searched_text' on.
        // $doi_column: the row's 'doi' column, if the input file has one (e.g. "10.1002/ecy.1680"); used when the citation has no DOI of its own.
        $full_reference = self::clean_full_reference($full_reference, $changes); // what is searched and read; the report keeps the original full_reference too
        $ref = $this->parse_full_reference($full_reference);
        if(self::year_lost($changes) && !self::year_as_volume($full_reference, $ref['year'])) $ref['year'] = ''; // (Nov 23, 2026) FishBase lost the year: a year elsewhere in the text isn't it (see TEXT_CHANGES 'zero'); a volume numbered by its year stays, e.g. "Copeia 1993(3):665"
        $row = array_fill_keys(array_slice(self::OUTPUT_COLUMNS, 2), '');
        $row['searched_text'] = $full_reference;
        $row['text_changes'] = implode('; ', $changes);   // every change made before the search, in plain words; empty = none
        $row['change_codes'] = array_keys($changes);      // not a column: counted for the guide
        foreach(array('author', 'year', 'journal', 'volume', 'issue') as $k) $row["parsed_$k"] = $ref[$k];
        $row['parsed_page'] = $ref['page'] . ($ref['page_end'] !== '' ? '-' . $ref['page_end'] : '');
        if($cands = self::doi_candidates($full_reference, $span)) { // the citation gives its own DOI: no search
            foreach($cands as $doi) { $r = $this->doi_given($row, $doi, $full_reference); if(!self::doi_unregistered($r)) return $r; }
            /* (Nov 22, 2026) neither CrossRef nor DataCite has it (mistyped, or cut short): never used as the DOI. The citation without it is searched
               like any other, and the note says which DOI it gave. Before, it was 'possible' with that DOI, e.g. "https://doi.org/10.2305/IUCN.UK". */
            $s2 = str_replace("\xC2\xA0", ' ', $full_reference);
            $without = trim(preg_replace('/\s+/', ' ', substr($s2, 0, $span[0]) . ' ' . substr($s2, $span[0] + $span[1])), " .,;");
            $r = $this->find_DOI($without, $doi_column);
            if(!in_array($r['status'] ?? '', array('stop', 'error'))) {
                $r['searched_text'] = $without;
                $r['text_changes'] = trim("left out its DOI " . $cands[0] . " (neither CrossRef nor DataCite has it)" . (($row['text_changes'] ?? '') !== '' ? "; " . $row['text_changes'] : '') . (($r['text_changes'] ?? '') !== '' ? "; " . $r['text_changes'] : ''));
                $r['review_note'] = "The citation gives the DOI " . $cands[0] . ", but neither CrossRef nor DataCite has it (mistyped or cut short), so the citation was searched without it. " . ($r['review_note'] ?? '');
                $r['change_codes'] = array_values(array_unique(array_merge($row['change_codes'] ?? array(), $r['change_codes'] ?? array())));
            }
            return $r;
        }
        if(($doi = self::doi_in_column($doi_column)) !== '') { // the input file's doi column gives it: no search
            $r = $this->doi_given($row, $doi, $full_reference, true);
            if(!self::doi_unregistered($r)) return $r;
            $r = $this->find_DOI($full_reference); // (Nov 22, 2026) neither CrossRef nor DataCite has it: never used; the citation is searched (see above)
            if(!in_array($r['status'] ?? '', array('stop', 'error')))
                $r['review_note'] = "The input file's doi column gives the DOI $doi, but neither CrossRef nor DataCite has it (mistyped or cut short), so the citation was searched. " . ($r['review_note'] ?? '');
            return $r;
        }
        if(($note = self::not_searched_note($full_reference)) !== '') return array_merge($row, array('status' => 'skipped', 'review_note' => $note));
        if(($note = $this->min_year_note($ref['year'])) !== '') { $this->min_year_skips++; return array_merge($row, array('status' => 'skipped', 'review_note' => $note)); } // MIN YEAR (only when the run asks)
        if(preg_match(self::DATASET_CITATION, $full_reference)) { // (Nov 19, 2026) a dataset cited as one: DataCite first, e.g. "... [Dataset]. Dryad.", "Data from: ..."
            $d = $this->datacite_route(array_merge($row, array('status' => 'not found', 'checks' => '')), $ref, $full_reference, true);
            if(($d['status'] ?? '') === 'stop' || ($d['DOI_source'] ?? '') === 'DataCite') return $d; // else CrossRef, as for any citation
        }
        $items = $this->search_crossref($full_reference, $ref['year']);
        if($items === 'stop') return array('status' => 'stop', 'change_codes' => array()); // CrossRef keeps asking us to slow down: the search stops before this row
        if($items === false) return array_merge($row, array('status' => 'error', 'review_note' => 'CrossRef could not be reached. Run again: this row will be searched again.'));
        if(!$items) {
            $row = array_merge($row, array('status' => 'not found', 'review_note' => $this->note_no_candidates($ref)));
            return $this->datacite_route($row, $ref, $full_reference); // DATACITE: a second search, when no volume or pages were read
        }

        // the best candidate: matched > possible > not found; then the most checks that agree; then CrossRef's order (best score first)
        $rank = array('matched' => 2, 'possible' => 1, 'not found' => 0); $best = null; $lookups = 0;
        foreach($items as $i => $item) {
            $c = $this->check_candidate($ref, $item);
            $author_score = 1; $title_share = 0; $item['_authors'] = '';
            if($this->author_title_checks) { // AUTHOR/TITLE: only for candidates that pass the other checks; they never reject one
                $title_share = (float) self::title_share($full_reference, (string) @$item['title'][0]);
                if($c['status'] == 'not found' && $c['fails'] === array('no volume') && $ref['author'] !== '') {
                    /* No volume or pages in the citation -- a book, or a style without them (e.g. Vancouver "Journal. 1917 May") -- and nothing else differs:
                       'possible' (never 'matched') when the first author agrees (or nearly), at least 90% of CrossRef's title is in the citation (all of a title of up to 9 words), and,
                       when the candidate is in a journal, the journal is named in the citation. Books like "Kofoid & Swezy 1921" have BHL DOIs in CrossRef.
                       Not 80%: "Allobates zaparo" (IUCN) then got "Allobates alessandroi: IUCN SSC Amphibian Specialist Group" (86%), and Micha et al. another Azolla paper (83%). */
                    $share = self::title_share($full_reference, (string) @$item['title'][0]);
                    $jshare = self::title_share($full_reference, (string) @$item['container-title'][0], 1);
                    // (Nov 19, 2026) the container must be named only for a journal article: a book chapter's container is a book or series
                    // the citation often doesn't name, e.g. Bradshaw 1981 "Pages 1395-1434 in A. Keast, editor. Ecological Biogeography of Australia" in "Monographiae Biologicae"
                    $jneeded = (string) @$item['type'] === 'journal-article';
                    if($share !== null && $share >= 0.9 && (!$jneeded || $jshare === null || $jshare >= 0.7)) {
                        $authors = ($lookups < self::MAX_AUTHOR_LOOKUPS || isset($item['_authors_given'])) ? $this->crossref_authors($item, $lookups) : null;
                        if($authors === 'stop') return array('status' => 'stop', 'change_codes' => array());
                        $a = $this->author_title_check($ref['author'], $authors, $full_reference, (string) @$item['title'][0]);
                        if($a['score'] >= 2) {
                            $c['status'] = 'possible'; $c['via'] = 'author and title'; $c['why'] = '';
                            $jin = $jshare !== null && $jshare >= 0.7;
                            $c['checks'] = array_merge(array_values(array_diff($c['checks'], $jin ? array('volume=unknown', 'journal=unknown') : array('volume=unknown'))), $jin ? array('journal=in the citation') : array(), $a['checks']);
                            $c['agree'] += 2 + ($jin ? 1 : 0); $author_score = $a['score']; $item['_authors'] = is_array($authors) ? implode('; ', $authors) : '';
                        }
                    }
                }
                elseif($c['status'] == 'not found' && $c['fails'] && !array_diff($c['fails'], array('journal', 'volume', 'issue', 'page')) && count($c['fails']) <= 2
                       && array_intersect($c['checks'], array('year=OK', 'year=next to it')) && $ref['author'] !== '' && $title_share >= 0.9 && self::title_share($full_reference, (string) @$item['title'][0]) !== null) {
                    /* (added Nov 19, 2026) TYPO: the year agrees, but one or two of journal / volume / issue / page don't -- a typo in the citation
                       ("Amphibia-Reptilia 27" for 28, "Geological Magazine 25" for 3) or the journal's old name ("Journal of Eukaryotic Microbiology 8" is
                       "The Journal of Protozoology" 8) -- while the first author agrees exactly and at least 90% of CrossRef's title (3+ words) is in the citation:
                       'possible' (never 'matched'), with a note naming what differs. */
                    $authors = ($lookups < self::MAX_AUTHOR_LOOKUPS || isset($item['_authors_given'])) ? $this->crossref_authors($item, $lookups) : null;
                    if($authors === 'stop') return array('status' => 'stop', 'change_codes' => array());
                    $a = $this->author_title_check($ref['author'], $authors, $full_reference, (string) @$item['title'][0]);
                    if($a['score'] >= 3) {
                        $c['status'] = 'possible'; $c['via'] = 'typo'; $c['why'] = '';
                        $c['checks'] = array_merge($c['checks'], $a['checks']); // no extra 'agree', and ranked below any other 'possible' (see $key)
                        $author_score = $a['score']; $item['_authors'] = is_array($authors) ? implode('; ', $authors) : '';
                    }
                }
                elseif($c['status'] != 'not found') {
                    $authors = ($ref['author'] !== '' && ($lookups < self::MAX_AUTHOR_LOOKUPS || isset($item['_authors_given']))) ? $this->crossref_authors($item, $lookups) : null;
                    if($authors === 'stop') return array('status' => 'stop', 'change_codes' => array());
                    $a = $this->author_title_check($ref['author'], $authors, $full_reference, (string) @$item['title'][0]);
                    $c['checks'] = array_merge($c['checks'], $a['checks']);
                    $author_score = $a['score']; $item['_authors'] = is_array($authors) ? implode('; ', $authors) : '';
                    if($a['differs'] && $c['status'] == 'matched') $c['status'] = 'possible'; // the numbers agree but the authors don't: a person checks it
                    // both disagree -- the authors differ AND under 30% of CrossRef's title is in the citation -- so it's another work: not found.
                    // e.g. "King, R.P. Growth performance of Nigerian fish stocks. Naga 20(2):31-35" vs a paper on chickens by Ibe, vol. 20 p. 25-31.
                    // One alone is never enough: CrossRef's names can be garbled (given names as surnames), and many citations have no title.
                    $share = self::title_share($full_reference, (string) @$item['title'][0]);
                    if($a['differs'] && $share !== null && $share < 0.3) { $c['status'] = 'not found'; $c['why'] = 'author and title'; }
                    // (added Nov 19, 2026) CrossRef has no volume for it (e.g. "Mammalian Species" 593 vs JSTOR's record): the first author must agree exactly,
                    // as in a series like that every number starts at page 1 in the same year
                    // and the title (Nov 19, 2026): when CrossRef's journal agrees, half of its title (3+ words) in the citation; when the journal can't be compared
                    // (e.g. a BHL book), 75% of it. Else the same author's other work in the same year: e.g. Haddon 1891 "A revision of the British Actiniae" is not
                    // his "Reports on the zoological collections ... Torres Straits" (22%), Goren 1978 "Isr. J. Zool. 23" is not a 2025 checklist (60%, no year)
                    // A title that can't be compared word by word (e.g. "Arborimuspomo" for "Arborimus pomo") must be in the citation without its spaces: in a series
                    // like "Mammalian Species" one author wrote many numbers in one year, all starting at page 1, so without the title it's a guess.
                    elseif(in_array('volume=not in CrossRef', $c['checks']) && ($a['score'] < 3
                           || (in_array('journal=OK', $c['checks']) ? (($share === null || $share < 0.5) && !self::squashed_title_in($full_reference, (string) @$item['title'][0]))
                                                                    : ($share === null || $share < 0.75)))) { $c['status'] = 'not found'; $c['why'] = 'no CrossRef volume'; }
                }
            }
            // (Nov 19, 2026) no year to compare (e.g. FishBase's "Boulenger, G.A.0 ..."): 'matched' also needs the first author and the title to agree
            if($this->author_title_checks && $c['status'] == 'matched' && !preg_grep('/^year=/', $c['checks'])
               && !(preg_grep('/^author=(?:OK|nearly)/', $c['checks']) && preg_grep('/^title=OK/', $c['checks']))) $c['status'] = 'possible';
            if($this->author_title_checks && $c['status'] != 'not found' && ($why = $this->numbers_only_doubt($ref, $full_reference, $c, $item, $items, $i, $lookups)) !== '') {
                if($why === 'stop') return array('status' => 'stop', 'change_codes' => array());
                $c['status'] = 'not found'; $c['why'] = 'numbers only'; $c['doubt'] = $why;
            }
            $key = array($rank[$c['status']] - (($c['via'] ?? '') === 'typo' ? 0.5 : 0) - ((string) @$item['type'] === 'posted-content' ? 0.25 : 0), // TYPO: below any other 'possible';
                         $c['agree'], $author_score, $title_share, -$i);                                                                   // a preprint below the published article (Nov 19, 2026)
            if(!$best || $key > $best['key']) $best = array('key' => $key, 'c' => $c, 'item' => $item, 'i' => $i);
        }
        $c = $best['c']; $item = $best['item'];
        $c['also'] = $c['status'] == 'not found' ? array() : $this->same_pages_elsewhere($item, $items, $best['i']); // the same work registered twice?
        $clean = fn($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $row = array_merge($row, array('status' => $c['status'], 'DOI' => $c['status'] == 'not found' ? '' : (string) @$item['DOI'],
                     'crossref_DOI' => (string) @$item['DOI'], 'crossref_score' => round((float) @$item['score'], 1), 'crossref_type' => (string) @$item['type'],
                     'crossref_year' => self::item_year($item), 'crossref_journal' => $clean(@$item['container-title'][0]),
                     'crossref_volume' => $clean(@$item['volume']), 'crossref_issue' => $clean(@$item['issue']), 'crossref_page' => $clean(@$item['page']),
                     'crossref_title' => $clean(@$item['title'][0]), 'crossref_authors' => $item['_authors'], 'checks' => implode(' ', $c['checks'])));
        $row['DOI_URL'] = self::doi_url($row['DOI']);
        $row['DOI_source'] = $row['DOI'] !== '' ? 'CrossRef' : '';
        $row['review_note'] = $this->review_note($ref, $row, $c);
        if($row['status'] == 'not found') return $this->candidate_hint($this->datacite_route($row, $ref, $full_reference)); // DATACITE: a second search, when no volume or pages were read
        return $row;
    }
    private function candidate_hint($row)
    {   /* (Nov 19, 2026) For a row still 'not found': when the closest CrossRef candidate (crossref_DOI: the one the checks rank first, often but
           not always CrossRef's first) scored high, the review_note says it's worth a look.
           The DOI column stays empty: the citation can't confirm it, and CrossRef's search has no notion of "correct" -- it ranks records by
           the words they share with the citation and always returns some. On the Data Managers' list ("doi match tests", 3,561 citations),
           candidates of 'not found' rows scoring HINT_SCORE or more were the DM's DOI 9 times in 10 (102 of 114); the others were wrong,
           which is why they are never given as the DOI. */
        if(($row['status'] ?? '') !== 'not found' || (string) ($row['crossref_DOI'] ?? '') === '' || (float) ($row['crossref_score'] ?? 0) < self::HINT_SCORE) return $row;
        if(preg_match('/(?:^|\s)type=/', (string) ($row['checks'] ?? ''))) return $row; // rejected as not a work (a table of contents, a masthead, a preprint ...): never worth a look
        $row['review_note'] = rtrim($row['review_note']) . " Worth a look: the closest CrossRef candidate, " . $row['crossref_DOI'] . " (score " . $row['crossref_score'] . "), "
            . "is often the right one at this score (about 9 in 10 on the Data Managers' list), but this citation can't confirm it: open it to check.";
        return $row;
    }
    private function datacite_route($row, $ref, $citation, $dataset = false)
    {   // $dataset (Nov 19, 2026): the citation says it's a dataset (DATASET_CITATION): searched first, even with a volume read (e.g. "In supplement to: ... 32(5)")
   /* DATACITE: for a row CrossRef couldn't confirm, with no volume or pages read (a dataset, report, thesis or book) and a first author read:
           DataCite's top 5 candidates, each checked:
             - at least 90% of its title words are in the citation (PANGAEA has series of near-twins: "... at Station JUNE-9", "... in winter 1995")
             - the first author agrees, or nearly
             - the year is within 1 of the cited one, when both are known; a cited year inside its title doesn't count ("... Aegean Sea in winter 1995", 2008)
             - never a GBIF "Occurrence Download" (a user's download, not the dataset)
           When several pass with the same title and first author (e.g. a PANGAEA dataset and its GBIF copy, 10.15468/...), the one that isn't a GBIF
           copy is given and the note names the other. At most 'possible'. Returns the row: unchanged when nothing passes. */
        if(!$this->datacite_search || ($ref['volume'] !== '' && !$dataset) || $ref['author'] === '') return $row;
        // (Nov 19, 2026) a dataset citation: first its title with all its words (the text between the year and "[Dataset]"), as DataCite's ranking of
        // any-word searches puts copies first (e.g. PANGAEA's "Time series of phytoplankton ... at station L4 ..." under 4 "Widdicombe et al 2010" copies);
        // then the usual search, without "In supplement to: <the article>", whose words pull in other records
        $items = array();
        if($dataset && preg_match('/(?:\(\s*(?:1[5-9]|20)\d\d[a-z]?\s*\)|\b(?:1[5-9]|20)\d\d[a-z]?)\s*[.:]?\s*:?\s*(.{10,300}?)\s*\[\s*data\s*-?\s*set\s*\]/iu', $citation, $tm)) {
            $items = $this->search_datacite(preg_replace('/^Data from:\s*/i', '', $tm[1]), true);
            if($items === 'stop') return array('status' => 'stop', 'change_codes' => array());
            if(!is_array($items)) $items = array();
        }
        $more = $this->search_datacite($dataset ? trim(preg_replace('/\bIn supplement to:.*$/is', '', $citation)) : $citation);
        if($more === 'stop') return array('status' => 'stop', 'change_codes' => array());
        $have = array_map(fn($d) => strtolower((string) ($d['attributes']['doi'] ?? @$d['id'])), $items);
        if(is_array($more)) foreach($more as $d) if(!in_array(strtolower((string) ($d['attributes']['doi'] ?? @$d['id'])), $have)) $items[] = $d;
        if(!$items) return $row; // DataCite couldn't be reached, or has nothing: the CrossRef result stands
        $ok = array();
        foreach($items as $d) {
            $at = (array) @$d['attributes']; $title = trim((string) @$at['titles'][0]['title']); $doi = (string) ($at['doi'] ?? @$d['id']);
            if($doi === '' || $title === '' || preg_match('/^Occurrence Download/i', $title)) continue;
            if(preg_match(self::FIGURE_COPY_TITLE, $title) || strcasecmp((string) @$at['types']['resourceTypeGeneral'], 'Image') == 0) continue; // a figure copied to Zenodo, not the work cited (Nov 19, 2026)
            $pub = is_array(@$at['publisher']) ? (string) @$at['publisher']['name'] : (string) @$at['publisher'];
            if(stripos($pub, 'Plazi') !== false && stripos($citation, 'Plazi') === false) continue; // a Plazi copy of an article (on GBIF), not the article cited (Nov 19, 2026)
            $share = self::title_share($citation, $title);
            if($share === null || $share < 0.9) continue;
            $names = array();
            foreach((array) @$at['creators'] as $cr) { $n = trim((string) ($cr['familyName'] ?? '')); if($n === '') $n = trim(explode(',', (string) ($cr['name'] ?? ''))[0]); if($n !== '') $names[] = $n; }
            $a = $this->author_title_check($ref['author'], $names, $citation, $title);
            if($a['score'] < 2) continue;
            $year = (string) ($at['publicationYear'] ?? ''); $ycheck = 'year=unknown';
            if($ref['year'] !== '' && $year !== '') {
                $diff = abs((int) $ref['year'] - (int) $year);
                if($diff <= 1) $ycheck = $diff ? 'year=next to it' : 'year=OK';
                elseif(str_contains($title, $ref['year'])) $ycheck = "year=$year (the cited {$ref['year']} is in its title)";
                else continue;
            }
            $publisher = is_array(@$at['publisher']) ? (string) @$at['publisher']['name'] : (string) @$at['publisher'];
            $ok[] = array('doi' => $doi, 'title' => $title, 'names' => $names, 'year' => $year, 'publisher' => $publisher,
                          'type' => (string) (@$at['types']['resourceTypeGeneral'] ?: @$at['types']['resourceType']), 'checks' => array_merge(array($ycheck), $a['checks']));
        }
        if(!$ok) return $row;
        // the cited year first (versions of one dataset, e.g. Zenodo "Version 4", 2021 vs 2020: Nov 19, 2026), then a GBIF copy last (stable: DataCite's order otherwise)
        // (Nov 19, 2026) and before those: the repository named in the citation ("[Dataset]. Dryad." -> Dryad, not a Zenodo copy), and a dataset before a file in it (dryad.101tb, not dryad.101tb/1)
        $cited_repo = function($o) use ($citation) { $pub = strtolower(trim($o['publisher'])); return $pub !== '' && stripos($citation, explode(' ', $pub)[0]) !== false; };
        $is_part = function($o) use ($ok) { foreach($ok as $p) if($p['doi'] !== $o['doi'] && str_starts_with(strtolower($o['doi']), strtolower($p['doi']) . '/')) return true; return false; };
        $sort_key = fn($o) => array((int) !$cited_repo($o), (int) $is_part($o), (int) !in_array('year=OK', $o['checks']), (int) str_starts_with($o['doi'], '10.15468/'));
        usort($ok, fn($x, $y) => $sort_key($x) <=> $sort_key($y));
        $b = $ok[0]; $norm = fn($t) => implode(' ', self::words($t));
        $also = array();
        foreach(array_slice($ok, 1) as $o) if($norm($o['title']) === $norm($b['title'])) $also[] = $o['doi'] . (str_starts_with($o['doi'], '10.15468/') ? ' (a GBIF copy)' : '');
        $what = $b['type'] !== '' ? $b['type'] : 'record';
        $agree = $this->agreed_text($b['checks']);
        $note = ($dataset ? "The citation is a dataset; found in DataCite: \"" : "Not in CrossRef; found in DataCite: \"") . "" . mb_substr($b['title'], 0, 140) . "\" ($what" . ($b['publisher'] !== '' ? ", " . $b['publisher'] : '') . ($b['year'] !== '' ? ", " . $b['year'] : '') . ")"
              . ($b['names'] ? " by " . implode('; ', array_slice($b['names'], 0, 4)) . (count($b['names']) > 4 ? ' et al.' : '') : '') . ". $agree."
              . (str_contains(implode(' ', $b['checks']), 'is in its title') ? " The cited year ({$ref['year']}) is in its title; DataCite's year is {$b['year']}." : '')
              . ($also ? " DataCite also has " . implode(' and ', $also) . " with the same title." : '')
              . " Check that this is the " . strtolower($what) . " cited.";
        return array_merge($row, array('status' => 'possible', 'DOI' => $b['doi'], 'DOI_URL' => self::doi_url($b['doi']), 'DOI_source' => 'DataCite',
            'checks' => trim($row['checks'] . ' | DataCite: ' . implode(' ', $b['checks'])), 'review_note' => $note));
    }
    private function search_datacite($text, $all_words = false)
    {   // $all_words (Nov 19, 2026): the words joined with AND (DataCite's default), for a dataset's title
        /* DATACITE: DataCite's top 5 records for a citation (https://api.datacite.org/dois?query=...&sort=relevance), politely and cached like CrossRef's answers.
           Its query has a syntax ("-" = NOT, ":" = a field, quotes, brackets ...) and joins words with AND, so a citation word that isn't in the record
           (a co-author, a publisher) would find nothing: the words (3+ characters, at most 40, without a DOI or link) are joined with OR and
           ranked by relevance, e.g. "Gotsis OR Skretas OR Pagou OR ... OR Aegean OR winter OR 1995". Returns the list, false (couldn't be read) or 'stop'. */
        $t = preg_replace('~(?:https?://\S+|\bdoi\s*[:.]?\s*10\.\S+)~i', ' ', $text);
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $t, $w);
        $words = array_slice(array_values(array_unique(array_filter($w[0], fn($x) => !in_array(strtoupper($x), array('AND', 'NOT'))))), 0, 40);
        if(!$words) return array();
        $url = $this->datacite_url . "?" . http_build_query(array('query' => implode($all_words ? ' ' : ' OR ', $words), 'sort' => 'relevance', 'page[size]' => 5,
                                                                  'fields[dois]' => 'doi,titles,creators,publicationYear,types,publisher'));
        if(Functions::url_already_cached($url, $this->datacite_options)) $json = Functions::lookup_with_cache($url, $this->datacite_options);
        else { $json = $this->call_crossref($url, $this->datacite_options); if($json === 'stop') return 'stop'; if($json === false || $json === 'missing') return false; }
        $d = @json_decode((string) $json, true);
        return is_array($d['data'] ?? null) ? $d['data'] : false;
    }
    private function datacite_work($doi)
    {   // DATACITE: DataCite's record of one DOI (https://api.datacite.org/dois/<DOI>): its 'attributes', 'missing' (DataCite doesn't have it), null (couldn't be read) or 'stop'
        $url = $this->datacite_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"));
        if($this->missing_cached($url, $this->datacite_options)) return 'missing'; // CACHE: a 404 before
        if(Functions::url_already_cached($url, $this->datacite_options)) $json = Functions::lookup_with_cache($url, $this->datacite_options);
        else { $json = $this->call_crossref($url, $this->datacite_options); if($json === 'stop' || $json === 'missing') return $json; if($json === false) return null; }
        $d = @json_decode((string) $json, true);
        return is_array($d['data']['attributes'] ?? null) ? $d['data']['attributes'] : null;
    }
    private function doi_given($row, $doi, $citation, $from_column = false)
    {   // $from_column: the DOI is from the input file's doi column, not the citation: the same checks; the notes and DOI_source say so
        $r = $this->doi_given_checks($row, $doi, $citation);
        if(!$from_column || !isset($r['review_note'])) return $r;
        if(($r['DOI_source'] ?? '') === 'citation') $r['DOI_source'] = 'doi column';
        $r['review_note'] = str_replace(array('The citation itself gives this DOI', 'The citation gives the DOI'), array("The input file's doi column gives this DOI", "The input file's doi column gives the DOI"), $r['review_note']);
        $r['checks'] = str_replace('doi=in the citation', 'doi=in the doi column', (string) ($r['checks'] ?? ''));
        return $r;
    }
    private function doi_given_checks($row, $doi, $citation)
    {   /* The citation gives its own DOI, e.g. "... doi:10.1594/PANGAEA.692046" or "DOI: 10.1080/09670262.2014.982202,2014.": CrossRef's record of it is read
           (one call, cached) instead of a search. A DOI can be mistyped or cut short in a citation, so:
             - CrossRef has it and its title is in the citation (or can't be compared)  -> matched
             - CrossRef has it but its title isn't in the citation (under 50%)          -> possible: maybe a mistyped DOI
             - CrossRef doesn't have it                                                 -> possible: registered elsewhere (e.g. DataCite, for PANGAEA datasets), or mistyped */
        $work = $this->crossref_work($doi);
        if($work === 'stop') return array('status' => 'stop', 'change_codes' => array());
        if($work === null) return array_merge($row, array('status' => 'error', 'review_note' => 'The citation gives the DOI ' . $doi . ', but CrossRef could not be reached to check it. Run again: this row will be checked again.'));
        $row = array_merge($row, array('DOI' => $doi, 'DOI_URL' => self::doi_url($doi), 'DOI_source' => 'citation', 'crossref_DOI' => $doi));
        if($work === 'missing' && $this->datacite_search) { // DATACITE: is it a DataCite DOI? (e.g. PANGAEA datasets)
            $dc = $this->datacite_work($doi);
            if($dc === 'stop') return array('status' => 'stop', 'change_codes' => array());
            if(is_array($dc)) {
                $title = trim((string) @$dc['titles'][0]['title']); $share = self::title_share($citation, $title);
                $t = $share === null ? 'title=unknown' : 'title=' . ($share >= 0.7 ? 'OK' : ($share >= 0.3 ? 'partly' : 'not in citation')) . ' (' . round(100 * $share) . '%)';
                $desc = "\"" . mb_substr($title, 0, 140) . "\"" . (!empty($dc['publicationYear']) ? " (" . $dc['publicationYear'] . ")" : '');
                if($share !== null && $share < 0.5) return array_merge($row, array('status' => 'possible', 'checks' => "doi=in the citation, not in CrossRef, in DataCite $t",
                    'review_note' => "The citation itself gives this DOI; it is registered with DataCite (not CrossRef), but its title isn't in the citation: $desc. The DOI may be mistyped. Open the link to check."));
                return array_merge($row, array('status' => 'matched', 'checks' => "doi=in the citation, not in CrossRef, in DataCite $t",
                    'review_note' => "The citation itself gives this DOI, registered with DataCite (not CrossRef): $desc. Accept it."));
            }
        }
        if($work === 'missing') return array_merge($row, array('status' => 'possible', 'checks' => 'doi=in the citation, not in CrossRef' . ($this->datacite_search ? ' or DataCite' : ''),
            'review_note' => "The citation itself gives this DOI, but " . ($this->datacite_search ? "neither CrossRef nor DataCite has it: it may be registered elsewhere, or it may be mistyped." : "CrossRef doesn't have it: it may be registered elsewhere (e.g. with DataCite, as PANGAEA datasets are), or it may be mistyped.") . " Open the link: accept it if it opens the work cited."));
        $clean = fn($x) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $x), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $title = $clean(@$work['title'][0]); $share = self::title_share($citation, $title);
        $row = array_merge($row, array('crossref_type' => (string) @$work['type'], 'crossref_year' => self::item_year($work), 'crossref_journal' => $clean(@$work['container-title'][0]),
            'crossref_volume' => $clean(@$work['volume']), 'crossref_issue' => $clean(@$work['issue']), 'crossref_page' => $clean(@$work['page']), 'crossref_title' => $title,
            'crossref_authors' => implode('; ', array_filter(array_map(fn($a) => trim((string) ($a['family'] ?? $a['name'] ?? '')), (array) @$work['author'])))));
        $t = $share === null ? 'title=unknown' : 'title=' . ($share >= 0.7 ? 'OK' : ($share >= 0.3 ? 'partly' : 'not in citation')) . ' (' . round(100 * $share) . '%)';
        if($share !== null && $share < 0.5) return array_merge($row, array('status' => 'possible', 'checks' => "doi=in the citation, in CrossRef $t",
            'review_note' => "The citation itself gives this DOI, and CrossRef has it, but its title isn't in the citation: \"" . mb_substr($title, 0, 120) . "\". The DOI may be mistyped. Open the link to check."));
        return array_merge($row, array('status' => 'matched', 'checks' => "doi=in the citation, in CrossRef $t",
            'review_note' => "The citation itself gives this DOI, and CrossRef has it: \"" . mb_substr($title, 0, 120) . "\"" . ($row['crossref_year'] !== '' ? " (" . $row['crossref_year'] . ")" : '') . ". Accept it."));
    }
    private function crossref_work($doi)
    {   // CrossRef's record of one DOI (https://api.crossref.org/works/<DOI>, politely, cached): its 'message', 'missing' (404: CrossRef doesn't have it), null (couldn't be read) or 'stop'
        $url = $this->crossref_works_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"));
        if($this->missing_cached($url)) return 'missing'; // CACHE: a 404 before
        if(Functions::url_already_cached($url, $this->crossref_options)) $json = Functions::lookup_with_cache($url, $this->crossref_options);
        else { $json = $this->call_crossref($url); if($json === 'stop' || $json === 'missing') return $json; if($json === false) return null; }
        $d = @json_decode((string) $json, true);
        return ($d['status'] ?? '') === 'ok' ? (array) $d['message'] : null;
    }
    private function crossref_authors($item, &$lookups)
    {   /* AUTHOR/TITLE: the authors' surnames of one CrossRef candidate. Returns a list (empty: CrossRef lists none), null (couldn't be read) or 'stop' (CrossRef asks us to slow down).
           AUTHORS IN THE SEARCH: from the search answer itself (no call); for a search answered before that change, from the record
           https://api.crossref.org/works/<DOI>, politely and cached like the search. */
        if(isset($item['_authors_given'])) return self::author_surnames((array) @$item['author']);
        $doi = (string) @$item['DOI'];
        if($doi === '') return null;
        $url = $this->crossref_works_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"));
        if($this->missing_cached($url)) return null; // CACHE: a 404 before
        if(Functions::url_already_cached($url, $this->crossref_options)) $json = Functions::lookup_with_cache($url, $this->crossref_options);
        else { $lookups++; $json = $this->call_crossref($url); if($json === 'stop') return 'stop'; if($json === false || $json === 'missing') return null; }
        $d = @json_decode((string) $json, true);
        if(($d['status'] ?? '') !== 'ok') return null;
        return self::author_surnames((array) @$d['message']['author']);
    }
    private static function author_surnames($list)
    {   // CrossRef's 'author' list => surnames ('family', or 'name' for an organization)
        $names = array();
        foreach($list as $a) { $n = trim((string) ($a['family'] ?? $a['name'] ?? '')); if($n !== '') $names[] = $n; }
        return $names;
    }
    private function author_title_check($surname, $authors, $citation, $title)
    {   /* AUTHOR/TITLE: the checks to add, a score for ranking candidates (3 = the author agrees, 2 = nearly, 1 = can't tell, 0 = differs), and whether it differs.
           The surname may be any of CrossRef's authors; accents and capitals don't count; up to 2 letters off counts as nearly (OCR slips, e.g. "Boulesger"). */
        $r = array('checks' => array(), 'score' => 1, 'differs' => false);
        $norm = function($x) { $x = mb_strtolower((string) $x); $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $x); return preg_replace('/[^a-z]/', '', $t !== false ? $t : $x); };
        $sn = $norm($surname);
        if($sn === '')                        $r['checks'][] = 'author=unknown (none read from the citation)';
        elseif($authors === null)             $r['checks'][] = "author=unknown (CrossRef's authors couldn't be read)";
        elseif(!$authors)                     $r['checks'][] = 'author=unknown (CrossRef lists no authors)';
        else {
            $near = '';
            foreach($authors as $a) {
                $an = $norm($a); if($an === '') continue;
                if($an === $sn || (strlen($an) >= 4 && str_ends_with($sn, $an)) || (strlen($sn) >= 4 && str_ends_with($an, $sn))) { $r['checks'][] = 'author=OK'; $r['score'] = 3; $near = null; break; }
                if(min(strlen($an), strlen($sn)) >= 5 && levenshtein($an, $sn) <= 2) $near = $a;
            }
            if($near) { $r['checks'][] = "author=nearly ($near)"; $r['score'] = 2; }
            elseif($near === '') { $r['checks'][] = 'author=differs'; $r['score'] = 0; $r['differs'] = true; }
        }
        $share = self::title_share($citation, $title);
        if($share === null) $r['checks'][] = 'title=unknown';
        else $r['checks'][] = 'title=' . ($share >= 0.7 ? 'OK' : ($share >= 0.3 ? 'partly' : 'not in citation')) . ' (' . round(100 * $share) . '%)';
        return $r;
    }
    private static function squashed_title_in($citation, $title)
    {   // (Nov 19, 2026) CrossRef's title (6+ letters), without spaces and punctuation, is in the citation without them: "Pseudois nayaur and Pseudois schaeferi" in "Pseudoisnayaur and Pseudoisschaeferi"
        $sq = function($x) { $x = mb_strtolower(html_entity_decode(strip_tags((string) $x), ENT_QUOTES | ENT_HTML5, 'UTF-8')); $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $x); return preg_replace('/[^a-z]/', '', $t !== false ? $t : $x); };
        $t = $sq($title);
        return strlen($t) >= 6 && str_contains($sq($citation), $t);
    }
    private static function title_share($citation, $title, $min_words = 3)
    {   // AUTHOR/TITLE: the share of CrossRef's title words (3+ letters) that are in the citation; null for a title of fewer than $min_words such words
        $tw = self::words(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if(!$tw || count($tw) < $min_words) return null;
        $cw = array_flip(self::words($citation));
        return count(array_filter($tw, fn($w) => isset($cw[$w]))) / count($tw);
    }
    private function numbers_only_doubt($ref, $citation, $c, $item, $items, $i, &$lookups)
    {   /* (Nov 24, 2026) NUMBERS ONLY: a citation with no author and no title that CrossRef's title can be compared with, e.g. Brazilian Flora's
           "1990. Brittonia,42(3): 177,1990." -- only journal, volume, page and year are left to check, and many works share them (notes, reviews,
           errata, several short papers on one page). Such a candidate is kept only when the numbers point to one authored article:
             - the journal agrees (not just "couldn't be compared"): "1954. Bull. Mus. Natl. Hist. Nat.,ser. 2, 26: 551" is not CHEST Journal 26:551;
             - the page cited is confirmed (the article's first page, or inside its range): not "CrossRef has no pages" ("Erratum to Harley", Kew Bull. 44)
               nor "only the first page is known";
             - CrossRef lists authors for it: a record without any is a book review, an erratum, a note or a committee report, e.g. Brittonia 42(3): 177
               "New Generation Guide to Fungi of Britain and Europe." (a review);
             - no other candidate of the same journal and volume, with another title, is on that page too: Taxon 19: 304 has four
               ("General Committe", an erratum, "Trivalved Capsules in Bixa L.", "Typification of Nothocestrum").
           Returns '' (keep it), the reason in plain words (-> 'not found'), or 'stop'. */
        if($ref['author'] !== '') return '';
        $share = self::title_share($citation, (string) @$item['title'][0]);
        if($share !== null && $share >= 0.5) return ''; // the citation has CrossRef's title: not numbers only
        $authors = (isset($item['_authors_given']) || $lookups < self::MAX_AUTHOR_LOOKUPS) ? $this->crossref_authors($item, $lookups) : null;
        if($authors === 'stop') return 'stop';
        // an author the parser couldn't read is still there, e.g. "R.L. Soares Neto et al..", "Kuthubutheen; Nawawi.", "Poyarkov Jr., N. A.": not numbers only
        if(is_array($authors) && $authors) { $cw = array_flip(self::words($citation));
            foreach($authors as $a) foreach(self::words($a) as $w) if(mb_strlen($w) >= 3 && isset($cw[$w])) return ''; }
        // CrossRef's authors unknown (not in a search answered before AUTHORS IN THE SEARCH) or none: only a short citation is numbers only, at most
        // 3 words besides the journal's, e.g. "Krause, K.. 1914. Notizbl. Königl. Bot. Gart. Berlin,6: 145" -- not "Simpson and Galbraith-Jour. Physiology,
        // Vol. 33, p. 225." or a titled citation whose author the parser missed ("Poyarkov Jr., N. A., ... (2018). ''A new genus ...'' Zoological research")
        if(!(is_array($authors) && $authors) && count(array_diff(self::words($citation), self::words($ref['journal']))) > 3) return '';
        if(!in_array('journal=OK', $c['checks'])) return "the journal name couldn't be compared";
        if(!preg_grep('/^page=(?:first page|in )/', $c['checks'])) return "the page cited couldn't be confirmed in the article's pages";
        if(is_array($authors) && !$authors) return "CrossRef lists no authors for it (often a review, an erratum or a note)";
        $cited = (int) $ref['page']; $norm = fn($t) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(strip_tags((string) $t)));
        foreach($items as $j => $it) {
            if($j == $i || $norm(@$it['title'][0]) === $norm(@$item['title'][0])) continue;
            if(ltrim(trim((string) @$it['volume']), '0') !== ltrim(trim((string) @$item['volume']), '0') || $this->journal_agrees($ref['journal'], (string) @$it['container-title'][0]) !== true) continue;
            if(preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/', trim((string) @$it['page']), $p) && ($cited == (int) $p[1] || (isset($p[2]) && $cited > (int) $p[1] && $cited <= (int) $p[2])))
                return "another work in CrossRef is on the same page (" . $it['DOI'] . ", \"" . mb_substr(trim(strip_tags((string) @$it['title'][0])), 0, 60) . "\")";
        }
        return '';
    }
    private function same_pages_elsewhere($best, $items, $best_i)
    {   // other candidates with the same journal, volume and first page as the one chosen, and another DOI: the same work registered twice, perhaps
        $first = fn($p) => preg_match('/^\s*(\d+)/', (string) $p, $m) ? $m[1] : '';
        $also = array();
        foreach($items as $i => $it) {
            if($i == $best_i || (string) @$it['DOI'] === (string) @$best['DOI'] || $first(@$it['page']) === '') continue;
            if(mb_strtolower((string) @$it['container-title'][0]) === mb_strtolower((string) @$best['container-title'][0])
               && ltrim((string) @$it['volume'], '0') === ltrim((string) @$best['volume'], '0') && $first(@$it['page']) === $first(@$best['page'])
               && (trim((string) @$best['volume']) !== '' || trim((string) @$it['issue']) === trim((string) @$best['issue']))) // no volume (Mammalian Species): the same number too (Nov 19, 2026)
                $also[] = $it['DOI'] . " (" . (@$it['issue'] !== null && @$it['issue'] !== '' ? "issue " . $it['issue'] . ", " : '') . "pages " . @$it['page'] . ", " . self::item_year($it) . ")";
        }
        return $also;
    }
    static function doi_in_text($s)
    {   // a DOI written in the citation itself, e.g. "doi:10.1594/PANGAEA.692046", "DOI: 10.1080/09670262.2014.982202,", "https://doi.org/10..."; '' if none
        $c = self::doi_candidates($s);
        return $c ? $c[0] : '';
    }
    static function doi_candidates($s, &$span = null)
    {   /* The DOI written in the citation, as a list to try in order (empty: none); $span = array(start, length) of its text, with "doi:" or the link.
           (Nov 22, 2026) - a non-breaking space ends it like a space: "...T58571A11805014.en.<nbsp>Downloaded on ..." gave the DOI "...en. Downloaded"
                          - a DOI split by a space is joined first, then the part before the space is tried: "10.2305/IUCN.UK .2004.RLTS.T54548A11165473.en"
                            and "10.2305/IUCN.UK.2013- 2.RLTS.T59494A16943488.en" (IUCN Red List citations) */
        $s = str_replace("\xC2\xA0", ' ', (string) $s); // a non-breaking space -> a space; $span is on this text (find_DOI() cuts the same text)
        if(!preg_match('~(?:\bdoi\s*[:.]?\s*|https?://(?:dx\.)?doi\.org/)(10\.\d{4,9}/[^\s"<>,]+)~i', $s, $m, PREG_OFFSET_CAPTURE)) return array(); // no comma: in "DOI 10.1007/s40415-014-0048-3,2014." the year follows
        $cut = self::trim_doi($m[1][0]); $end = $m[1][1] + strlen($m[1][0]); $out = array();
        $rest = substr($s, $m[1][1] + strlen($cut));
        if(preg_match('~^\s+(\.[A-Za-z0-9][^\s"<>,]*)~', $rest, $j) || (str_ends_with($cut, '-') && preg_match('~^\s+(\d[^\s"<>,]*)~', $rest, $j))) {
            $out[] = self::trim_doi($cut . $j[1]); $end = $m[1][1] + strlen($cut) + strlen($j[0]);
        }
        $out[] = $cut;
        $span = array($m[0][1], $end - $m[0][1]);
        return array_values(array_unique(array_filter($out, fn($d) => $d !== '')));
    }
    private static function trim_doi($doi)
    {   while($doi !== '' && (strpbrk(substr($doi, -1), '.,;:') !== false || (substr($doi, -1) === ')' && substr_count($doi, '(') < substr_count($doi, ')'))
                              || (substr($doi, -1) === ']' && substr_count($doi, '[') < substr_count($doi, ']')))) $doi = substr($doi, 0, -1); // "[DOI:10.1126/science.1102289]" (Nov 19, 2026)
        return $doi; // a ")" or "]" that closes one inside it stays, e.g. 10.1016/0044-8486(93)90035-w, 10.1645/0022-3395(2001)087[0340:FLNSAO]2.0.CO;2
    }
    private static function doi_unregistered($r)
    {   // doi_given() found that neither CrossRef nor DataCite has the DOI (not 'in DataCite')
        return ($r['status'] ?? '') === 'possible' && str_contains((string) @$r['checks'], 'not in CrossRef') && !str_contains((string) @$r['checks'], 'in DataCite');
    }
    static function not_searched_note($text)
    {   // why a full_reference (cleaned, with no DOI given) is not sent to CrossRef: its review_note; '' = it is searched. Used by find_DOI() and the estimate.
        if(mb_strlen($text) < self::MIN_LENGTH)         return 'Too short to search.';
        if(preg_match(self::BARE_LINK, $text))          return 'Only a link, not a citation: not searched.';
        if(preg_match(self::NO_SPACE, $text))           return 'An identifier or code with no spaces, not a citation: not searched.';
        if(preg_match(self::GENBANK_TITLE, $text) || preg_match(self::GENBANK_TITLE_FORMS, $text)) return 'A GenBank sequence title, not a citation: not searched.';
        if(preg_match(self::CHALCID_DB_CODE, $text))    return 'A Universal Chalcidoidea Database code, not a citation: not searched.';
        if(preg_match(self::AFD_PAGE, $text))           return 'An Australian Faunal Directory page, not a citation with a DOI: not searched.';
        if(preg_match(self::FISHBASE_WEB_PAGE, $text))  return 'An OBIS distribution map or an FAO-FIGIS species fact sheet (a web page), not a citation with a DOI: not searched.';
        if(preg_match(self::SHORT_CITATION, $text))     return 'Only a first author, year, journal and volume (no title or page), not enough to confirm a DOI: not searched.';
        if(preg_match(self::AUTHOR_YEAR_ONLY, $text))   return 'Only authors and a year, not enough to confirm a DOI: not searched.';
        return '';
    }
    private static function min_year_param($param)
    {   // MIN YEAR (Nov 25, 2026): the run's "min_year" as a year (e.g. 1900 or "1900"); 0 when not given (off); false when it isn't a year
        $v = ((array) $param)['min_year'] ?? null;
        if($v === null || $v === '' || $v === 0 || $v === false) return 0;
        return (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) && (int) $v >= 1000 && (int) $v <= (int) date('Y') + 1 ? (int) $v : false;
    }
    private function min_year_note($year)
    {   // why a row is skipped by the run's "min_year" (its review_note); '' = it is searched: no limit, no year read, or the year isn't before it.
        // $year: the year read from the citation (find_DOI's, after its own fixes). Used by find_DOI() and the estimate.
        if(!$this->min_year || !preg_match('/^\d{4}$/', (string) $year) || (int) $year >= $this->min_year) return '';
        return "Published in $year, before {$this->min_year} (this run's \"min_year\"): not searched. CrossRef has almost no DOIs for works that old.";
    }
    static function doi_in_column($s)
    {   // a DOI from a 'doi' column: "10.1002/ecy.1680", "doi:10...", "https://doi.org/10..."; '' if it doesn't hold one (e.g. "10.101/6/j.pocean.2015.05.011")
        $s = trim((string) $s);
        if($s === '') return '';
        if(preg_match('~^10\.\d{4,9}/~', $s)) $s = "doi:$s"; // the first DOI, e.g. "10.12933/therya-20-785 ISSN 2007-3364", "10.1111/...x, 10.1098/rspb.2007.1547"
        return self::doi_in_text($s);
    }
    static function doi_url($doi)
    {   // the DOI as a link, e.g. 10.1016/0044-8486(93)90035-w -> https://doi.org/10.1016/0044-8486(93)90035-w; '' when there is no DOI.
        // Characters that would break a URL (space, %, ", #, ?, <, >, ...) are percent-encoded; "/" and the usual DOI characters are kept as they are.
        $doi = trim((string) $doi);
        if($doi === '') return '';
        return "https://doi.org/" . preg_replace_callback('/[^A-Za-z0-9\-._~\/:;()\[\]@!$&\'*+,=]/', fn($m) => rawurlencode($m[0]), $doi);
    }
    // every pre-processing rule of clean_full_reference(): its code => what the guide says about it
    const TEXT_CHANGES = array(
        'spaces' => "Spaces at the start or end of the full_reference were removed.",
        'quotes' => "Double quotes around the whole full_reference were removed, and doubled quotes inside (\"\") made single, e.g. '\"Lebour, M. V. (1917). ...\"' -> 'Lebour, M. V. (1917). ...'. A spreadsheet adds them when it saves a file as text.",
        'html'   => "HTML codes were written as the characters they stand for, e.g. 'Côte d&#39;Ivoire' -> 'Côte d'Ivoire', 'Lucr&#259;ri' -> 'Lucrări', '&amp;' -> '&', so the title and journal read as written (Nov 23, 2026; 2,051 rows of reference_fishbase.tab).",
        'ref_n'  => "A FishBase reference number at the very end, e.g. '(Ref. 3)', was removed. It is FishBase's own number, not part of the citation, and can mislead CrossRef.",
        'zero'   => "A stray '0' glued to the last author name, where FishBase exports put the year, was removed, e.g. 'Ruwet0 Ecology' -> 'Ruwet. Ecology', 'E.0 Tilapiine' -> 'E. Tilapiine', '(ed.)0 Tilapia' -> '(ed.) Tilapia' (the '(ed.)' is kept). The year itself is missing in these citations, so the year couldn't be checked. (Nov 23, 2026) When the citation also ends with FishBase's reference number, no year is taken from elsewhere in the text, as it isn't the citation's: e.g. a species authority, '... Coryphaena hippurus (Linnaeus, 1758) in Maltese waters ... Medit. Mar. Sci. 16(2):334-345'; so the CrossRef search isn't limited to the wrong years. A year where the volume goes stays the year, e.g. 'Copeia 1993(3):665-687', 'Proc. Zool. Soc. Lond. 1899:98-143'. Journal, volume, page, first author and title are still checked.",
        'genbank_link' => "A GenBank record link at the end, e.g. '... Appl. Environ. Microbiol. 75 (23), 7527-7536 (2009). https://www.ncbi.nlm.nih.gov/nuccore/EU554750', was removed. It points to one sequence, not to the paper; the same paper is cited once per sequence, each with its own link, so without it they share one CrossRef search (on the 3.5M-row reference.tab, Nov 23, 2026: 20,625 rows, about 960 different citations).",
        'bibtex' => "A BibTeX entry, e.g. '@article{57591, author = {Charles, J.G.}, journal = {Biocontrol Science and Technology}, pages = {405-418}, title = {A survey of mealybugs ...}, volume = {3(4)}, year = {1993} }', was written as a plain citation: 'Charles, J.G. (1993). A survey of mealybugs .... Biocontrol Science and Technology, 3(4), 405-418.', so its journal, volume, year and page can be read and checked. A doi field is kept as 'doi:...', so that DOI is checked instead of searched. Only the fields of a citation are used (authors, year, title, journal or book, volume, number, pages, publisher, doi); notes, abstracts, keywords and URLs are left out.");
    private static function drop_title($j)
    {   /* "Title. Journal" -> "Journal", for citations like "Boucher RC (2004) New concepts of ... lung disease. Eur Resp J 23: 146-158", where the
           title sits between the year and the journal. Cut at the last ". " (or "? ", "! ") after a word (e.g. "disease.", "NBD1.") and before a capital
           letter, whose left side reads like a title:
           at least 3 full words (4+ letters, not abbreviations ending in "."). Abbreviated journal names are never cut, e.g.
           "Mem. Torrey Bot. Club", "Bot. J. Linn. Soc.", "Proc. R. Soc. Lond., Ser. B: Biol. Sci." stay whole. */
        if(!preg_match_all('/[\p{L}\d]{2,}[.?!]\s+(?=\p{Lu})/u', $j, $b, PREG_OFFSET_CAPTURE)) return $j;
        foreach(array_reverse($b[0]) as $x) {
            $cut = $x[1] + strlen($x[0]);
            if(preg_match_all('/\b\p{L}{4,}\b(?![.])/u', substr($j, 0, $cut), $w) >= 3) return substr($j, $cut);
        }
        return $j;
    }
    private static function year_lost($changes)
    {   /* (Nov 23, 2026) a FishBase citation whose year was lost: FishBase's '0' where the year was ('zero') and its reference number at the end ('ref_n').
           Both, as the '0' rule alone also fires on other citations that have their year, e.g. "... (2015). ... Version 1.0 Smithsonian ..." (4 in GloBI's reference.tab). */
        return isset($changes['zero']) && isset($changes['ref_n']);
    }
    private static function year_as_volume($s, $year)
    {   // (Nov 23, 2026) the year is where the volume goes, e.g. "Copeia 1993(3):665-687", "Proc. Zool. Soc. Lond. 1899:98-143": a real year, kept when FishBase lost the year
        return $year !== '' && (bool) preg_match('/(?<![\w.])' . $year . '\s*(?:\([^()]{1,20}\))?\s*:\s*\d/', $s);
    }
    static function clean_full_reference($s, &$changes = null)
    {   /* $changes: code => the change in plain words, for this text (empty: nothing was changed). Every rule is in TEXT_CHANGES.
           Removes what only confuses CrossRef, before the search (the report keeps the original full_reference):
             - quotes around the whole text, added by a spreadsheet: "\"Lebour, M. V. (1917). ...\"" -> "Lebour, M. V. (1917). ..." (and "" inside -> ")
             - a FishBase reference number at the end, e.g. "... ICLARM Conf. Proc. 7. (Ref. 3)" -> "... ICLARM Conf. Proc. 7."
             - a FishBase "0" glued to the authors where the year should be (the year is lost), e.g.
               "Trewavas, E.0 Tilapiine fishes ..." -> "Trewavas, E. Tilapiine fishes ...", "J.-C. Ruwet0 Ecology ..." -> "J.-C. Ruwet. Ecology ..."
               "Pullin, R.S.V. (ed.)0 Tilapia genetic ..." -> "Pullin, R.S.V. (ed.) Tilapia genetic ..." (after an editor's "(ed.)", "(eds.)", "(comp.)", kept)
               only the first one, and only a lone 0 right after a letter, a dot or a ")" and right before a capital letter */
        $changes = array(); $s = (string) $s;
        if(($t = trim($s)) !== $s) { $changes['spaces'] = "removed spaces at the start or end"; $s = $t; }
        // quotes a spreadsheet adds around a field when it saves it as text, with the quotes inside doubled: "Lebour, M. V. ""The Peridiniales ..."" ..."
        if(strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
            $inner = substr($s, 1, -1); $doubled = str_contains($inner, '""');
            $changes['quotes'] = "removed the quotes around the whole citation" . ($doubled ? ", and made the doubled quotes inside single" : '');
            $s = trim(str_replace('""', '"', $inner));
        }
        // (Nov 23, 2026) HTML codes written as their characters: "Côte d&#39;Ivoire" -> "Côte d'Ivoire", "Lucr&#259;ri" -> "Lucrări"
        if(preg_match('/&(?:#\d{1,7}|#x[0-9a-f]{1,6}|[a-z][a-z0-9]{1,30});/i', $s) && ($t = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== $s) {
            $changes['html'] = "wrote HTML codes as their characters (e.g. '" . (preg_match('/&(?:#\d{1,7}|#x[0-9a-f]{1,6}|[a-z][a-z0-9]{1,30});/i', $s, $e) ? $e[0] : '') . "')"; $s = $t;
        }
        if(preg_match('/\s*(\(Ref\.\s*\d+\))\s*$/u', $s, $m)) { $changes['ref_n'] = "removed '" . $m[1] . "' at the end"; $s = rtrim(substr($s, 0, -strlen($m[0]))); }
        if(preg_match('/(\S*[\p{L}.)])0(?=\s+\p{Lu})/u', $s, $m, PREG_OFFSET_CAPTURE)) { // only the first one: "E.0 " -> "E. ", "Ruwet0 " -> "Ruwet. ", "(ed.)0 " -> "(ed.) "
            $word = $m[1][0]; $fixed = (str_ends_with($word, '.') || str_ends_with($word, ')')) ? $word : $word . '.';
            $changes['zero'] = "removed a stray '0' after the authors ('" . $word . "0' -> '" . $fixed . "')";
            $s = substr($s, 0, $m[0][1]) . $fixed . substr($s, $m[0][1] + strlen($m[0][0]));
        }
        // (Nov 23, 2026) a GenBank record link at the end: "... 75 (23), 7527-7536 (2009). https://www.ncbi.nlm.nih.gov/nuccore/EU554750" -> "... 75 (23), 7527-7536 (2009)."
        // Only after a citation (a bare link stays, and is not searched: BARE_LINK); a link anywhere else in the text stays.
        if(preg_match(self::GENBANK_LINK_AT_END, $s, $m) && trim(substr($s, 0, -strlen($m[0]))) !== '') {
            $changes['genbank_link'] = "removed the GenBank link at the end ('" . trim($m[0]) . "')"; $s = rtrim(substr($s, 0, -strlen($m[0])));
        }
        if(($b = self::bibtex_to_citation($s)) !== '') { $changes['bibtex'] = "wrote the BibTeX entry as a plain citation"; $s = $b; } // (Nov 22, 2026)
        return $s; // nothing else changes, so every other full_reference keeps its cached CrossRef answer
    }
    static function bibtex_fields($s)
    {   // (Nov 22, 2026) a BibTeX entry '@article{key, author = {...}, year = {1993}, ...}' => array('type' => 'article', 'fields' => array(name => value)); null if it isn't one
        if(!preg_match('/^@([a-z]+)\s*\{[^,{}]*,/i', $s, $m)) return null;
        $type = strtolower($m[1]); $i = strlen($m[0]); $n = strlen($s); $fields = array();
        while(preg_match('/\G[\s,]*([a-z_]+)\s*=\s*/i', $s, $f, 0, $i)) {
            $i += strlen($f[0]); $name = strtolower($f[1]);
            if($i < $n && $s[$i] === '{') { // a value in braces, with braces inside it: {Revisin de las {Laboulbeniales} {Argentinas}}
                for($depth = 0, $j = $i; $j < $n; $j++) { if($s[$j] === '{') $depth++; elseif($s[$j] === '}' && --$depth == 0) break; }
                if($j >= $n) return null;
                $v = substr($s, $i + 1, $j - $i - 1); $i = $j + 1;
            }
            elseif($i < $n && $s[$i] === '"') { if(($j = strpos($s, '"', $i + 1)) === false) return null; $v = substr($s, $i + 1, $j - $i - 1); $i = $j + 1; }
            elseif(preg_match('/\G[^,}\s]+/', $s, $w, 0, $i)) { $v = $w[0]; $i += strlen($v); }
            else break;
            $v = preg_replace('/\\\\[`\'^"~=.uvHtcdbk]\s*\{?\s*(\p{L})\s*\}?/u', '$1', $v); // LaTeX accents: {\'e} -> e
            $v = preg_replace('/\\\\[a-z]+\s*|\\\\/i', '', $v);                                   // other LaTeX commands
            $v = trim(preg_replace('/\s+/u', ' ', str_replace(array('{', '}'), '', $v)));
            if($v !== '' && !isset($fields[$name])) $fields[$name] = $v;
        }
        return $fields ? array('type' => $type, 'fields' => $fields) : null;
    }
    static function bibtex_to_citation($s)
    {   /* (Nov 22, 2026) a BibTeX entry as a plain citation, '' if $s isn't one (see TEXT_CHANGES 'bibtex'):
           "Authors (year). Title. Journal, volume(number), pages. doi:..." -- e.g. 12,512 rows of the big reference.tab, mostly from the Universal Chalcidoidea Database.
           Before, the whole entry went to CrossRef, and none of its parts could be read or checked: 1 matched in the first 752. */
        if(!($b = self::bibtex_fields($s))) return '';
        $f = $b['fields']; $type = $b['type'];
        $authors = array_values(array_filter(array_map('trim', preg_split('/\s+and\s+/i', $f['author'] ?? ($f['editor'] ?? '')))));
        $who = count($authors) > 1 ? implode(', ', array_slice($authors, 0, -1)) . ' & ' . end($authors) : ($authors[0] ?? '');
        if(!isset($f['author']) && $who !== '') $who .= count($authors) > 1 ? ' (eds.)' : ' (ed.)';
        $year = preg_match('/\b(1[5-9]|20)\d\d\b/', $f['year'] ?? '', $y) ? $y[0] : '';
        $title = rtrim($f['title'] ?? '', ' .');
        $pages = str_replace(array('--', '–', '—'), '-', $f['pages'] ?? '');
        $vol = $f['volume'] ?? '';
        if(($f['number'] ?? '') !== '' && !str_contains($vol, '(')) $vol .= ($vol !== '' ? '(' . $f['number'] . ')' : $f['number']);
        if(in_array($type, array('article', 'misc'))) $in = $f['journal'] ?? ($f['booktitle'] ?? '');
        elseif(isset($f['booktitle']) && $type !== 'book') $in = 'In: ' . ($f['editor'] ?? '') . (isset($f['editor']) ? ' (ed.), ' : '') . $f['booktitle'];
        else $in = $f['publisher'] ?? ($f['school'] ?? ($f['institution'] ?? ''));
        if(in_array($type, array('book', 'inbook', 'incollection')) && isset($f['publisher']) && !str_contains($in, $f['publisher'])) $in .= ($in !== '' ? '. ' : '') . $f['publisher'];
        $out = $who . ($year !== '' ? " ($year)" : '') . '.';
        if($title !== '') $out .= " $title.";
        $tail = implode(', ', array_filter(array(rtrim($in, ' .'), $vol, $pages), fn($x) => $x !== ''));
        if($tail !== '') $out .= " $tail.";
        if(($doi = trim(preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $f['doi'] ?? ''))) !== '' && str_starts_with($doi, '10.')) $out .= " doi:$doi";
        return trim(preg_replace('/\s+/u', ' ', $out));
    }
    function parse_full_reference($s)
    {   /* Reads the year, journal, volume, issue and page(s) from a full_reference, e.g.
             "1998. Brittonia,50(1): 59,1998."                                     -> 1998, Brittonia, 50, 1, 59
             "Saint-Hilaire, A. & Tulasne. 1842. Ann. Sci. Nat., Bot.,ser. 2, 17: 138,1842." -> 1842, "Ann. Sci. Nat., Bot.,ser. 2", 17, '', 138
             "Bartoli, A. ... 1999. Revisión ... (Asteraceae: Astereae).,Kurtziana 27(3):327-359.,1999." -> 1999, Kurtziana, 27, 3, 327-359
           Anything it can't read is left blank, and that check is skipped. */
        $ref = array('text' => $s, 'year' => '', 'journal' => '', 'journal_zone' => '', 'volume' => '', 'issue' => '', 'page' => '', 'page_end' => '', 'author' => '');
        // the first author's surname: the words before the first comma, letters only, e.g. "Boulenger, G.A. ..." -> Boulenger, "Thys van den Audenaerde, D.F.E." -> as is
        //   also "Knowles MR, Boucher RC (2002) ..." and "Boucher RC (2004) ..." (surname + initials, no comma) -> Knowles, Boucher; a leading list number "1. " is skipped (not a year: "1998. Brittonia,50(1): 59" has no author)
        //   also initials first (added Nov 19, 2026): "A. A. Heidebrecht;R. MacVicar;..." -> Heidebrecht, "B. Sandel, J. D. Corbin, ..." -> Sandel
        if(preg_match('/^\s*(?:\d{1,3}[.)]\s+)?(?:\p{Lu}\.\s*(?:-\s*\p{Lu}\.\s*)?){1,4}(\p{Lu}[\p{L}\'\x{2019}\-]+)\s*[;,.]/u', $s, $m)) $ref['author'] = $m[1];
        elseif(preg_match('/^\s*(?:\d{1,3}[.)]\s+)?(\p{L}[\p{L}\'\x{2019}\- ]{1,40}?)\s*,/u', $s, $m)) $ref['author'] = trim(preg_replace('/\s+\p{Lu}{1,3}$/u', '', trim($m[1])));
        elseif(preg_match('/^\s*(?:\d{1,3}[.)]\s+)?(\p{Lu}[\p{L}\'\x{2019}\-]+)\s+\p{Lu}{1,3}\b/u', $s, $m)) $ref['author'] = $m[1];
        // a year; a page or a page range (a hyphen, an en dash or an em dash; also "ñ", an en dash garbled by a wrong text encoding, e.g. "Mammalian Species 593:1ñ5")
        $Y = '(1[5-9]\d\d|20\d\d)'; $P = '(\d{1,5})(?:\s*[-\x{2013}\x{2014}\x{00F1}]\s*(\d{1,5}))?';
        $g = fn($m, $i) => (isset($m[$i]) && $m[$i][1] >= 0) ? $m[$i][0] : ''; // a group of a PREG_OFFSET_CAPTURE match, '' when it didn't match
        // the year, in this order:
        //   reference.tab's own style, with the year repeated at the end ("1992. Bradea,9: 85 (1991) ...,1992."): the first one, as before (last rule)
        //   a year alone in parentheses, e.g. "Boucher RC (2004) ...", "Lebour, M. V. (1917). ..." (APA), "... (2008)." (Chicago)
        //   a year followed by a month, e.g. "... United Kingdom. 1963 Nov" (Vancouver) -- not "in August 1962" in its title
        //   else the first one that isn't part of a range, e.g. not "1904" in "the Third Tanganyika Expedition, 1904-1905" (a range isn't a publication year)
        $tab_style = (bool) preg_match('/,\s*' . $Y . '\.?\s*$/', $s);
        if(!$tab_style && preg_match('/\(' . $Y . '[a-z]?\)/', $s, $m, PREG_OFFSET_CAPTURE)) { $ref['year'] = $m[1][0]; $year_end = $m[1][1] + 4; }
        elseif(!$tab_style && preg_match('/\b' . $Y . '\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/', $s, $m, PREG_OFFSET_CAPTURE)) { $ref['year'] = $m[1][0]; $year_end = $m[1][1] + 4; }
        elseif(preg_match_all('/\b' . $Y . '\b/', $s, $years, PREG_OFFSET_CAPTURE)) foreach($years[1] as $y) {
            if(preg_match('/\d\s*(?:-|\x{2013})\s*$/u', substr($s, 0, $y[1])) || preg_match('/^\s*(?:-|\x{2013})\s*\d/u', substr($s, $y[1] + 4))) continue;
            $ref['year'] = $y[0]; $year_end = $y[1] + 4; break;
        }
        // the volume, issue and page(s), the last one of these:
        //   (a) Chicago "11, no. 2 (1917): 183-200", MLA "11.2 (1917): 183-200", or "11 (1917): 183-200": the year in parentheses is the publication year
        //   (b) "VOLUME[(ISSUE)]: PAGE[-PAGE]", e.g. "50(1): 59", "17: 138", "27(3):327-359", "81A:423-426" (volume 81 of Part A)
        //   (c) APA / Harvard "11(2), 183-200", "11(2), pp.183-200": only with a page range, so a lone number after a comma isn't taken for a page
        $m = null; $vol_at = null;
        if(preg_match_all('/(?<![\w.])(\d{1,4}[A-Z]?)(?:\.(\d{1,4})|\s*,\s*no\.\s*([\w\/-]{1,8}))?\s*\(\s*(?:\p{L}+\.?\s+)?' . $Y . '\s*\)\s*:\s*' . $P . '/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $m = end($all);
            $ref['volume'] = $g($m, 1); $ref['issue'] = $g($m, 2) !== '' ? $g($m, 2) : $g($m, 3); $ref['year'] = $g($m, 4); $ref['page'] = $g($m, 5); $ref['page_end'] = $g($m, 6);
            $vol_at = $m[0][1]; unset($year_end); // the year comes after the journal here
        }
        elseif(preg_match_all('/(?<![\w.])(\d{1,4}[A-Z]?)\s*(?:\(([^()]{1,20})\))?\s*:\s*' . $P . '/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)
            || (preg_match_all('/(?<![\w.])(\d{1,4}[A-Z]?)\s*(?:\(([^()]{1,20})\))?\s*,\s*(?:pp?\.\s*)?(\d{1,5})\s*[-\x{2013}\x{2014}\x{00F1}]\s*(\d{1,5})(?!\d)/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)
                && ($all = array_values(array_filter($all, fn($x) => !(preg_match('/^' . $Y . '$/', $x[3][0]) && preg_match('/^' . $Y . '$/', $x[4][0])
                       && ($ref['year'] === '' || in_array($ref['year'], array($x[3][0], $x[4][0]), true))))))) ) {
                // not a year range, e.g. "Select. Hexandr. Pl.,9,1831-1834" (the cited year is one of them); but pages that only look like years are pages,
                // e.g. "1979. ... Can. J. Zool., 57, 1835-1844." (Nov 19, 2026)

            $m = end($all);
            $ref['volume'] = $g($m, 1); $ref['issue'] = trim($g($m, 2)); $ref['page'] = $g($m, 3); $ref['page_end'] = $g($m, 4);
            $vol_at = $m[0][1];
            if($ref['volume'] === $ref['year']) { $ref['volume'] = ''; $ref['year_volume'] = $ref['year']; } // e.g. "Mem. Math. Phys. Inst. Par.,1811: 82" -> the year, not a volume
            // ... unless CrossRef's volume is that year: "Copeia 1972:493-508" is Copeia volume 1972 (check_candidate, Nov 19, 2026)
        }
        //   (d) (added Nov 19, 2026) pages before the journal, the volume last, e.g. "A. Bensch. 1847. Ueber die ... Fleischfresser. pp. 221-227. Annalen der Chemie und Pharmacie. vol. 61"
        elseif(preg_match('/\bpp?\.\s*' . $P . '\s*\.\s+(\p{L}[^\t]{1,150}?)[.,]?\s+vol\.\s*(\d{1,4}[A-Z]?)\b(?:\s*[.,]\s*(?:no\.|issue)\s*(\d{1,4}))?/u', $s, $m)) {
            $ref['page'] = $m[1]; $ref['page_end'] = $m[2] ?? ''; $ref['journal'] = $ref['journal_zone'] = trim($m[3]); $ref['volume'] = $m[4]; $ref['issue'] = $m[5] ?? '';
        }
        //   (e) (added Nov 19, 2026) "Journal. vol. 46. pp. 233 - 240", e.g. "Brodie, E.D. ... 2012. New Salamanders of ... Guatemala. Journal of Herpetology. vol. 46. pp. 233 - 240."
        //       also (Nov 19, 2026) "Volume 9, Issue 2, Pages 212–216", "Sarsaria, vol 64, p 363-369"
        elseif(preg_match('/(?:[.,]\s*|\s)vol(?:ume)?\.?\s*(\d{1,4}[A-Z]?)\s*[.,]\s*(?:(?:no\.?|issue)\s*(\d{1,4})\s*[.,]\s*)?(?:pages?|pp?)\.?\s*' . $P . '/iu', $s, $m, PREG_OFFSET_CAPTURE)) {
            $ref['volume'] = $g($m, 1); $ref['issue'] = $g($m, 2); $ref['page'] = $g($m, 3); $ref['page_end'] = $g($m, 4);
            $vol_at = $m[0][1];
        }
        //   (f) (Nov 19, 2026) volume (issue) and a page range with only spaces, e.g. "Proc. Natl. Acad. Sci. U.S.A. 114 (40) 10695-10700"
        elseif(preg_match_all('/(?<![\w.])(\d{1,4})\s*\((\d{1,4})\)\s+(\d{1,5})\s*[-\x{2013}\x{2014}]\s*(\d{1,5})(?!\d)/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $m = end($all); $ref['volume'] = $g($m, 1); $ref['issue'] = $g($m, 2); $ref['page'] = $g($m, 3); $ref['page_end'] = $g($m, 4); $vol_at = $m[0][1];
        }
        //   (g) (Nov 19, 2026) an article number: "PLoS ONE 11(11): e0166108" (volume and issue; the page can't be compared) and
        //       "Sci Rep 11, 396 (2021)" (volume and article number, which CrossRef keeps as the page)
        elseif(preg_match_all('/(?<![\w.])(\d{1,4})\s*(?:\((\d{1,4})\))?\s*:\s*e\d{4,8}\b/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $m = end($all); $ref['volume'] = $g($m, 1); $ref['issue'] = $g($m, 2); $vol_at = $m[0][1];
        }
        elseif(preg_match_all('/(?<![\w.])(\d{1,4})\s*,\s*(\d{1,6})\s*\(\s*' . $Y . '\s*\)/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $m = end($all); $ref['volume'] = $g($m, 1); $ref['page'] = $g($m, 2); $ref['year'] = $g($m, 3); $vol_at = $m[0][1]; unset($year_end);
        }
        if($vol_at !== null) { // the journal
            $before = substr($s, 0, $vol_at);
            // a title in quotes (Chicago, MLA): the journal is what follows it, e.g. '"The Peridiniales ... breakwater." Journal of the ... Kingdom 11, no. 2'
            // (not a name in a journal title, e.g. 'Anais Bot. Herb. "Barbosa Rodrigues"': a quoted title ends with ".", "?" or "!" inside the quotes)
            if(preg_match_all('/["\x{201C}][^"\x{201C}\x{201D}]{10,}[.?!]["\x{201D}]/u', $before, $q, PREG_OFFSET_CAPTURE)) {
                $last = end($q[0]);
                $ref['journal'] = trim(substr($before, $last[1] + strlen($last[0])), " .,;()");
            }
            elseif(isset($year_end) && $vol_at > $year_end) { // between the year and the volume; in "Title.,Journal 27(3)" the part after the last ".,"
                $j = trim(substr($s, $year_end, $vol_at - $year_end), " .,;()"); // e.g. ". Kew Bull.," -> "Kew Bull"; "(2004) Title. Eur Resp J" -> after ")"
                if(($p = strrpos($j, '.,')) !== false) $j = substr($j, $p + 2);
                $ref['journal_zone'] = $j; // the title may still be in it: check_candidate() also looks for CrossRef's journal at its end (journal_in_zone)
                $ref['journal'] = trim(preg_replace('/,\s*v(?:ol)?\.?$/i', '', trim(self::drop_title($j), " .,;")), " .,;"); // "Fl. Venez. Guayana,v. 6" -> without ",v" 
            }
            // (Nov 19, 2026) no year in front of the numbers (e.g. "Strassert ... nephridiophagids. Sci Rep 11, 396 (2021)."): journal_in_zone() looks at the text before them
            if($ref['journal'] === '' && $ref['journal_zone'] === '') $ref['journal_zone'] = trim(substr($s, 0, $vol_at), " .,;");
        }
        return $ref;
    }
    private function crossref_url($full_reference, $year, $with_authors = true)
    {   /* the CrossRef search for one full_reference; also the key of its cached answer.
           $with_authors = false: the URL used before AUTHORS IN THE SEARCH (Nov 22, 2026), to find a search answered before it in the cache */
        $q = array('query.bibliographic' => $full_reference, 'rows' => 5, 'select' => 'DOI,score,title,container-title,volume,issue,page,issued,published,type' . ($with_authors ? ',author' : ''));
        if($year) $q['filter'] = "from-pub-date:" . ($year - 1) . ",until-pub-date:" . ($year + 1);
        return $this->crossref_works_url . "?" . http_build_query($q);
    }
    function count_rows_to_search($file, $start = 1, $limit = 0)
    {   /* For an estimate before a search: of the rows a search of $file would do (same start/limit rules), how many are
             'new'     -> sent to CrossRef (about SECONDS_PER_NEW seconds each),
             'cached'  -> searched before: their answer comes from the cache, instantly,
             'skipped' -> not searched: not_searched_note() (too short, a link, an identifier, a GenBank title, a database code or page, only authors and a year).
           Makes no call. Returns array('rows' => n, 'new' => n, 'cached' => n, 'skipped' => n), or false. */
        if(!($in = @fopen($file, 'r'))) return false;
        $header = explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($in)), "\r\n"));
        $col_ref = array_search('full_reference', $header); $col_doi = array_search('doi', $header);
        if($col_ref === false) { fclose($in); return false; }
        $n = array('rows' => 0, 'new' => 0, 'cached' => 0, 'skipped' => 0); $row_no = 0; $start = max(1, (int) $start); $seen = array();
        while(($line = fgets($in)) !== false) {
            if(trim($line) === '') continue;
            $row_no++;
            if($row_no < $start) continue;
            if($limit && $n['rows'] >= $limit) break;
            $n['rows']++;
            $f = explode("\t", rtrim($line, "\r\n"));
            $this->count_one_row($n, $seen, (string) ($f[$col_ref] ?? ''), $col_doi !== false ? (string) ($f[$col_doi] ?? '') : '');
        }
        fclose($in);
        return $n;
    }
    function count_references_to_search($full_references)
    {   // the same count as count_rows_to_search(), for a list of full_reference texts (e.g. a DwCA's reference.tab read by AnalyzeMoF_API)
        $n = array('rows' => 0, 'new' => 0, 'cached' => 0, 'skipped' => 0); $seen = array();
        foreach($full_references as $full_reference) { $n['rows']++; $this->count_one_row($n, $seen, (string) $full_reference); }
        return $n;
    }
    private function count_one_row(&$n, &$seen, $full_reference, $doi_column = '')
    {   $ref = self::clean_full_reference(trim($full_reference), $changes); // as find_DOI() searches it, in the same order
        if(($doi = self::doi_in_text($ref)) !== '' || ($doi = self::doi_in_column($doi_column)) !== '') $url = $this->crossref_works_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/")); // a DOI given: CrossRef's record of it is read, no search (as find_DOI -> doi_given)
        elseif(self::not_searched_note($ref) !== '') { $n['skipped']++; return; } // not searched (find_DOI's rules)
        else { $year = $this->parse_full_reference($ref)['year']; if(self::year_lost($changes) && !self::year_as_volume($ref, $year)) $year = '';
               if($this->min_year_note($year) !== '') { $n['old'] = ($n['old'] ?? 0) + 1; $n['min_year'] = $this->min_year; return; } // MIN YEAR: skipped, not searched
               $url = $this->crossref_url($ref, $year); $old = $this->crossref_url($ref, $year, false); }
        $missing_known = isset($doi) && $doi !== '' && $this->missing_cached($url) && (!$this->datacite_search
                         || ($dc = $this->datacite_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"))) && (Functions::url_already_cached($dc, $this->datacite_options) || $this->missing_cached($dc, $this->datacite_options)));
        if(isset($seen[$url]) || $missing_known || Functions::url_already_cached($url, $this->crossref_options) || (isset($old) && Functions::url_already_cached($old, $this->crossref_options))) $n['cached']++; // a repeat is asked only once; a search answered before AUTHORS IN THE SEARCH is cached too
        else { $n['new']++; $seen[$url] = 1; }
    }
    static function estimate_text($n)
    {   // e.g. "Estimated time: about 4.8 hours (5,799 references: 5,750 new, sent to CrossRef at about 3 seconds each; 40 cached, instant; 9 too short)"
        $s = $n['new'] * self::SECONDS_PER_NEW;
        if($s < 60)          $t = "less than a minute";
        elseif($s < 90)      $t = "about 1 minute";
        elseif($s < 5400)    $t = "about " . round($s / 60) . " minutes";
        elseif($s < 172800)  $t = "about " . rtrim(rtrim(number_format($s / 3600, 1), '0'), '.') . " hours";
        else                 $t = "about " . rtrim(rtrim(number_format($s / 86400, 1), '0'), '.') . " days";
        return "Estimated time: $t" . ($s >= 60 ? ", to finish around " . date("Y-m-d H:i T", time() + (int) round($s)) : '')
             . " (" . number_format($n['rows']) . " references: " . number_format($n['new']) . " new, sent to CrossRef at about " . self::SECONDS_PER_NEW . " seconds each; "
             . number_format($n['cached']) . " searched before, instant from the cache" . ($n['skipped'] ? "; " . number_format($n['skipped']) . " skipped (not citations, or too little to search)" : '')
             . (!empty($n['old']) ? "; " . number_format($n['old']) . " skipped as published before " . $n['min_year'] . " (\"min_year\")" : '') . ")";
    }
    private function search_crossref($full_reference, $year)
    {   /* the top 5 CrossRef candidates (best score first), only from the year and the years next to it when a year was read.
           false = this row failed (marked 'error', tried again on the next run); 'stop' = CrossRef keeps asking us to slow down: stop the search.
           A cached answer is used as is: the same full_reference is never sent twice. */
        $old = $this->crossref_url($full_reference, $year, false); // AUTHORS IN THE SEARCH: a search answered before it, without the authors
        $given = !Functions::url_already_cached($old, $this->crossref_options);
        $url = $given ? $this->crossref_url($full_reference, $year) : $old;
        if(Functions::url_already_cached($url, $this->crossref_options)) $json = Functions::lookup_with_cache($url, $this->crossref_options); // read from the cache, no call
        else {
            $json = $this->call_crossref($url);
            if($json === 'stop' || $json === false) return $json;
        }
        $d = @json_decode((string) $json, true);
        if(($d['status'] ?? '') !== 'ok') return false;
        $items = $d['message']['items'] ?? array();
        if($given) foreach($items as &$item) $item['_authors_given'] = true; // their 'author' lists came with the answer (a candidate without one has none)
        unset($item);
        return $items;
    }
    private function call_crossref($url, $opt = null)
    {   /* $opt: the options of the service called: crossref_options (default) or datacite_options (DATACITE): its cache, name and success test.
           One CrossRef call, politely: one at a time, a PAUSE after each call (see PACE), and our contact email in the User-Agent (CROSSREF_MAILTO).
           - 200: the answer is saved in the same cache lookup_with_cache() uses (so it's never asked again) and returned.
           - 429 (too many requests) or 503 (busy): wait -- CrossRef's Retry-After, else 1, 2, 4, 8 minutes -- and try again.
             After THROTTLE_LIMIT such answers in a row, returns 'stop': the search stops cleanly and can be resumed with "start".
           - anything else (no connection, 5xx, ...): tried 3 times, 5 seconds apart; then false (this row = 'error'). */
        $opt = $opt ?? $this->crossref_options; $name = $opt['name'];
        $pace = &$this->pace[$name]; // per service (CrossRef, DataCite): 'gap' between call starts from the x-rate-limit-* headers, 'last' call start
        if(!is_array($pace)) $pace = array('gap' => 0.0, 'last' => 0.0);
        for($attempt = 1, $other_failures = 0; ; $attempt++) {
            if(($wait = $pace['last'] + $pace['gap'] - microtime(true)) > 0) usleep((int) round($wait * 1000000)); // never faster than the headers' limit
            $shared = $this->shared_pace_take($name); // SHARED PACE: our turn, after any other process's call and pause
            $pace['last'] = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true,
                                         CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => $opt['user_agent']));
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $headers = $res === false ? '' : substr($res, 0, $header_size);
            $this->shared_pace_release($shared, microtime(true) + self::PAUSE_SECONDS); // SHARED PACE: the next call, by any process, after the pause
            usleep(self::PAUSE_SECONDS * 1000000); // the pause between calls, whatever the answer
            if(preg_match('/^x-rate-limit-limit:\s*(\d+)/mi', $headers, $m1) && (int) $m1[1] > 0) { // the limit it gives: e.g. 3 calls per "1s"
                $interval = preg_match('/^x-rate-limit-interval:\s*(\d+(?:\.\d+)?)\s*s/mi', $headers, $m2) ? (float) $m2[1] : 1.0;
                $pace['gap'] = 1.1 * $interval / (int) $m1[1];
            }
            $body    = $res === false ? '' : substr($res, $header_size);
            if($code == 200 && preg_match('/' . $opt['validation_regex'] . '/', $body)) {
                $this->throttled_in_a_row = 0;
                $this->save_to_cache($url, $body, $opt);
                return $body;
            }
            if($code == 404 && (str_contains($url, '/works/10.') || str_contains($url, '/dois/10.'))) { // a DOI it doesn't have (e.g. a DataCite DOI in CrossRef)
                $this->save_to_cache(self::MISSING_KEY . $url, "HTTP 404 on " . date('Y-m-d') . ": not registered here", $opt); // CACHE: never asked again (Nov 22, 2026)
                return 'missing';
            }
            if($code == 429 || $code == 503) {
                $this->throttled_in_a_row = ($this->throttled_in_a_row ?? 0) + 1;
                if($this->throttled_in_a_row >= self::THROTTLE_LIMIT) return 'stop';
                $wait = preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m) ? min((int) $m[1], 600) : 60 * (2 ** ($this->throttled_in_a_row - 1));
                echo "$name answered HTTP $code (" . ($code == 429 ? 'too many requests' : 'busy') . "): waiting " . $wait . " seconds, then trying again"
                   . " ($this->throttled_in_a_row of " . self::THROTTLE_LIMIT . " before stopping).\n";
                $this->shared_pace_push($name, microtime(true) + $wait, "$name answered HTTP $code"); // SHARED PACE: every process waits too
                sleep(max(1, $wait));
                continue;
            }
            if(++$other_failures >= 3) { echo "$name call failed (HTTP $code) 3 times; this row is marked 'error' and will be tried again on the next run.\n"; return false; }
            sleep(5);
        }
    }
    private function shared_pace_file($name)
    {   return $this->work_dir . "." . strtolower($name) . "_pace.json"; } // e.g. Reference_maintenance/.crossref_pace.json
    private function shared_pace_take($name)
    {   /* SHARED PACE: takes the service's lock (one call at a time, across processes) and waits until the next call may start.
           Returns the open file (kept locked during the call), or null if the file can't be used (then only this process's own pace applies). */
        if(!($h = @fopen($this->shared_pace_file($name), 'c+'))) return null;
        $t0 = microtime(true);
        if(!flock($h, LOCK_EX)) { fclose($h); return null; } // waits while another process is making its call
        $said = microtime(true) - $t0 > self::PACE_WAIT_NOTE_SECONDS;
        if($said) echo "Waited " . round(microtime(true) - $t0) . " seconds for another run's $name call to end (one call at a time, shared by every run).\n";
        rewind($h); $p = @json_decode((string) stream_get_contents($h), true);
        if(($wait = (float) ($p['next'] ?? 0) - microtime(true)) > 0) {
            if($wait > self::PACE_WAIT_NOTE_SECONDS) echo "Waiting " . round($wait) . " seconds before the next $name call: " . ($p['why'] ?? 'the shared pace') . " (shared by every run).\n";
            usleep((int) round(min($wait, 900) * 1000000));
        }
        return $h;
    }
    private function shared_pace_release($h, $next)
    {   // SHARED PACE: the next call (by any process) may start at $next; the lock is released
        if(!$h) return;
        ftruncate($h, 0); rewind($h); fwrite($h, json_encode(array('next' => $next, 'why' => 'the pause after each call', 'pid' => getmypid(), 'at' => date('Y-m-d H:i:s'))));
        fflush($h); flock($h, LOCK_UN); fclose($h);
    }
    private function shared_pace_push($name, $next, $why)
    {   // SHARED PACE: after a 429/503, every process waits as long as this one
        if(!($h = @fopen($this->shared_pace_file($name), 'c+'))) return;
        if(flock($h, LOCK_EX)) {
            rewind($h); $p = @json_decode((string) stream_get_contents($h), true);
            if($next > (float) ($p['next'] ?? 0)) { ftruncate($h, 0); rewind($h); fwrite($h, json_encode(array('next' => $next, 'why' => $why, 'pid' => getmypid(), 'at' => date('Y-m-d H:i:s')))); fflush($h); }
            flock($h, LOCK_UN);
        }
        fclose($h);
    }
    private function save_to_cache($url, $body, $opt = null)
    {   // the same file lookup_with_cache($url, $opt) reads: <cache>/crossref/ab/cd/<md5>.cache (DataCite: <cache>/datacite/...)
        $file = $this->cache_file($url, $opt);
        if(!is_dir(dirname($file))) @mkdir(dirname($file), 0777, true);
        @file_put_contents($file, $body);
    }
    private function cache_file($url, $opt = null)
    {   $opt = $opt ?? $this->crossref_options; $md5 = md5($url);
        return DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . $opt['resource_id'] . "/" . substr($md5, 0, 2) . "/" . substr($md5, 2, 2) . "/$md5.cache";
    }
    private function missing_cached($url, $opt = null)
    {   // CACHE: CrossRef (or DataCite) answered 404 for this DOI before: it isn't registered there, so it isn't asked again
        return is_file($this->cache_file(self::MISSING_KEY . $url, $opt));
    }
    private static function item_year($item)
    {   return (string) (@$item['issued']['date-parts'][0][0] ?: @$item['published']['date-parts'][0][0]);
    }
    function journal_agrees($ref_journal, $container)
    {   /* true / false / null (can't compare). Each word of the citation's abbreviation must be the start of a word of CrossRef's title,
           e.g. "Ann. Missouri Bot. Gard." ~ "Annals of the Missouri Botanical Garden"; "Mem. Torrey Bot. Club" !~ "Bulletin of the Torrey Botanical Club".
           One word may be missing only in a long abbreviation (5+ words). */
        $words = self::words($ref_journal); $cwords = self::words($container);
        if(!$words || !$cwords) return null;
        // (Nov 19, 2026) also a short abbreviation (up to 5 letters) whose letters are in the word in order, from its first letter: "Natl" ~ "National", "Expt" ~ "Experimental"
        $abbr = fn($w, $c) => strlen($w) <= 5 && $w[0] === $c[0] && preg_match('/^' . implode('.*', str_split($w)) . '/', $c);
        $miss = count(array_filter($words, function($w) use ($cwords, $abbr) { foreach($cwords as $c) if(str_starts_with($c, $w) || $abbr($w, $c)) return false; return true; }));
        return $miss == 0 || (count($words) >= 5 && $miss == 1);
    }
    function journal_in_zone($zone, $container)
    {   /* (added Nov 19, 2026) true when CrossRef's journal is at the end of $zone, the text between the year and the volume, where the title is often
           still in front of the journal because drop_title() couldn't cut it off, e.g.
             "Cyanobacteria-bryophytes symbioses. Journal of Experimental Botany" (a short title), "Arborimuspomo. Mammalian Species" (one word),
             "Gas-exchange ... (Reptilia, Amphisbaenia). Journal of Experimental Biology" (a title ending in ")."), "Bot. Res" cut from "Adv. Bot. Res":
           - a part after a ". ", "? ", "! " or ") " agrees with journal_agrees(), e.g. "Journal of Experimental Botany", "Adv. Bot. Res"
           - or CrossRef's journal, 2+ words, is in it word for word, e.g. "Journal of Zoology" in "Journal of Zoology, London"
           The volume, year and page are still checked, so a word of the title that happens to be a journal name isn't enough on its own. */
        $zone = trim((string) $zone);
        if($zone === '' || trim((string) $container) === '') return false;
        // only after a part that reads like a title -- a word of 4+ letters not cut short with a ".", or a long one (e.g. "Arborimuspomo.") -- and
        // only at the end of a sentence: after ")", "?", "!" or a word of 5+ letters and a "." ("symbioses.", "gecko."), never after an abbreviation
        // ("Bot.", "Mem.", "Zool."): "Mem. Torrey Bot. Club" must not give "Club" or "Torrey Bot. Club" (= "Bulletin of the Torrey Botanical Club", another series)
        // (Nov 22, 2026) also when closing quotes follow the end of the title, e.g. AmphibiaWeb's "''New World direct-developing frogs ...'' Zootaxa"
        // or "\"Title.\" Journal": before, "Zootaxa" wasn't found there, so such a citation was only 'possible' ("the journal differs")
        if(preg_match_all('/[.?!)](?:\'\'|["\x{201D}\x{2019}])?\s+/u', $zone, $b, PREG_OFFSET_CAPTURE))
            foreach($b[0] as $x) {
                $head = substr($zone, 0, $x[1] + 1);
                if(!preg_match('/\p{L}{4,}(?![\p{L}.])|\p{L}{8,}/u', $head)) continue;
                if(str_ends_with($head, '.') && !preg_match('/(?:\p{L}{5,}|\))\.$/u', $head)) continue; // "(Polychaeta). Acta Zool." is a sentence end too
                $tail = trim(substr($zone, $x[1] + strlen($x[0])), " .,;");
                if($tail !== '' && $this->journal_agrees($tail, $container) === true) return true;
            }
        $cw = array_values(array_diff(self::words($container), array('the', 'and'))); $zw = array_values(array_diff(self::words($zone), array('the', 'and')));
        if(count($cw) < 2) return false;
        for($i = 0; $i + count($cw) <= count($zw); $i++) if(array_slice($zw, $i, count($cw)) === $cw) return true;
        return false;
    }
    function check_candidate($ref, $item)
    {   // Returns array('status' => matched / possible / not found, 'checks' => what agreed (list), 'agree' => n checks that agree, 'why' => reason it isn't matched)
        $checks = array(); $ok = true; $why = ''; $fails = array();
        $fail = function($check, $reason) use (&$checks, &$ok, &$why, &$fails) { $checks[] = $check; if($ok) $why = $reason; $ok = false; $fails[] = $reason; };
        // type: a whole issue or volume is never the answer
        $type = (string) @$item['type'];
        if(in_array($type, self::NOT_AN_ARTICLE)) $fail("type=$type", 'not an article');
        // (Nov 19, 2026) a preprint or discussion paper ("posted-content") only when the citation itself is of one: otherwise the published article is cited,
        // e.g. "Strassert ... Sci Rep 11, 396 (2021)" is not its bioRxiv preprint of 2020
        elseif($type === 'posted-content' && !preg_match(self::PREPRINT_CITED, (string) ($ref['text'] ?? ''))) $fail("type=$type (a preprint)", 'not an article');
        // (Nov 19, 2026) a journal's table of contents, front matter etc., registered as an "article": never the answer,
        // e.g. "Australian Journal of Biological Sciences - Volume 30, 1977 - Table of Contents", "ContentSnapshots" (Annals of Botany)
        elseif(preg_match(self::NOT_A_WORK_TITLE, trim(strip_tags((string) @$item['title'][0])))) $fail("type=$type (" . mb_substr(trim(strip_tags((string) @$item['title'][0])), 0, 40) . ")", 'not an article');
        // journal
        $journal = $this->journal_agrees($ref['journal'], (string) @$item['container-title'][0]);
        if($journal !== true && $this->journal_in_zone($ref['journal_zone'] ?? '', (string) @$item['container-title'][0])) $journal = true; // the title was still in front of it
        if($journal !== true) foreach(self::RENAMED_JOURNALS as $old => $new) // a renamed journal (Nov 19, 2026)
            if(preg_match($old, ($ref['journal_zone'] ?? '') . ' ' . $ref['journal']) && preg_match($new, trim((string) @$item['container-title'][0]))) { $journal = true; $checks[] = 'journal_renamed'; break; }
        if($journal === true) $checks[] = 'journal=OK';
        elseif($journal === false) $fail('journal=differs', 'journal');
        else $checks[] = 'journal=unknown';
        // volume
        $num = fn($v) => ltrim(preg_replace('/^S\d+-/i', '', trim((string) $v)), '0'); // CrossRef's series volumes, e.g. "S3-102" for volume 102 of the 3rd series (Nov 19, 2026)
        $no_cr_volume = false;
        $cr_issue = trim((string) @$item['issue']);
        if($ref['volume'] !== '' && trim((string) @$item['volume']) === '' && ctype_digit($cr_issue) && ctype_digit($num($ref['volume']))) {
            // (Nov 19, 2026) no volume, but a number as the issue: JSTOR's "Mammalian Species" keep the number there, e.g. "Mammalian Species 192:1-3" is issue 192
            if($num($ref['volume']) === $num($cr_issue)) { $checks[] = 'volume=OK'; $checks[] = 'volume_as_issue'; }
            else $fail("volume=differs (CrossRef has issue $cr_issue)", 'volume');
        }
        elseif($ref['volume'] !== '' && trim((string) @$item['volume']) === '') { // (added Nov 19, 2026) e.g. a BHL book: nothing to compare.
            $checks[] = 'volume=not in CrossRef'; $no_cr_volume = true;    // At most 'possible', and find_DOI() also wants the first author and the title to agree.
        }
        elseif($ref['volume'] !== '') {
            if(strtoupper($num($ref['volume'])) === strtoupper($num(@$item['volume']))) $checks[] = 'volume=OK';
            // "81A" = volume 81 of "Part A": CrossRef often has volume "81" and the part in the journal title, e.g. "Comparative Biochemistry and Physiology Part A: Physiology"
            elseif(preg_match('/^(\d+)([A-Z])$/', $num($ref['volume']), $vl) && $vl[1] === $num(@$item['volume'])
                   && preg_match('/\bPart\s+' . $vl[2] . '\b/i', (string) @$item['container-title'][0])) $checks[] = 'volume=OK';
            else $fail('volume=differs', 'volume');
        }
        elseif(($ref['year_volume'] ?? '') !== '' && trim((string) @$item['volume']) === $ref['year_volume']) $checks[] = 'volume=OK'; // "Copeia 1972:493-508" (Nov 19, 2026)
        else $fail('volume=unknown', 'no volume'); // without a volume, a candidate can't be confirmed
        // issue: only when both are plain numbers, e.g. "2" vs "3"
        $issue = trim((string) @$item['issue']);
        if(ctype_digit($ref['issue']) && ctype_digit($issue) && !in_array('volume_as_issue', $checks)) {
            if($num($ref['issue']) === $num($issue)) $checks[] = 'issue=OK';
            else $fail('issue=differs', 'issue');
        }
        // year: the cited year can be a year off the issue date
        $y = self::item_year($item);
        if($ref['year'] !== '' && $y !== '') {
            $diff = abs((int) $ref['year'] - (int) $y);
            if($diff == 0) $checks[] = 'year=OK'; elseif($diff == 1) $checks[] = 'year=next to it'; else $fail('year=differs', 'year');
        }
        // page: the page cited is in the article's range (or is its first page)
        $page = trim((string) @$item['page']); $page_ok = null;
        if($ref['page'] !== '' && preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/', $page, $p)) {
            $first = (int) $p[1]; $last = isset($p[2]) ? (int) $p[2] : null; $cited = (int) $ref['page'];
            $cited_end = $ref['page_end'] !== '' ? (int) $ref['page_end'] : null;
            if($cited == $first)                                          { $page_ok = true;  $checks[] = "page=first page $first"; }
            // (Nov 19, 2026) a cited range that starts inside the article but runs past its end is another article on the next pages,
            // e.g. Lantz 1947 "134:52-56" is not "Observations on Discoglossus pictus" 37-52 but the "Note" on 52-56 (2 pages' slack for an end page off by one or two)
            elseif($last !== null && $cited > $first && $cited <= $last && $cited_end !== null && $cited_end > $cited && $cited_end > $last + 2)
                $fail("page=$cited-$cited_end not in $page", 'page');
            elseif($last !== null && $cited > $first && $cited <= $last)  { $page_ok = true;  $checks[] = "page=in $first-$last"; }
            elseif($last === null && $cited > $first)                     { $checks[] = "page=after first page $first, end unknown"; }
            else $fail("page=$cited not in $page", 'page');
        }
        else $checks[] = 'page=unknown';
        $agree = count(array_filter($checks, fn($c) => str_ends_with($c, '=OK') || str_starts_with($c, 'page=first') || str_starts_with($c, 'page=in')));
        if(!$ok) return array('status' => 'not found', 'checks' => $checks, 'agree' => $agree, 'why' => $why, 'fails' => $fails);
        $status = ($page_ok && $journal === true && !$no_cr_volume) ? 'matched' : 'possible'; // 'matched' needs the journal, the volume and the page confirmed
        return array('status' => $status, 'checks' => $checks, 'agree' => $agree, 'why' => '', 'fails' => array());
    }
    private function note_no_candidates($ref)
    {   $note = "CrossRef has no records" . ($ref['year'] ? " from " . ($ref['year'] - 1) . "-" . ($ref['year'] + 1) : '') . " that resemble this citation.";
        return $note . $this->note_old_work($ref);
    }
    private function note_old_work($ref)
    {   return ($ref['year'] !== '' && (int) $ref['year'] < 1900) ? " Works from before 1900 rarely have DOIs; the Biodiversity Heritage Library (biodiversitylibrary.org) may have it." : '';
    }
    private function review_note($ref, $row, $c)
    {   // what was found, in plain words, for the Data Managers' final check
        $cand = $row['crossref_journal'] === '' ? "a " . ($row['crossref_type'] !== '' ? $row['crossref_type'] : 'record') . " (" . $row['crossref_year'] . ")" // e.g. a book
              : "\"" . $row['crossref_journal'] . "\" " . $row['crossref_volume'] . ($row['crossref_issue'] !== '' ? "(" . $row['crossref_issue'] . ")" : '')
              . ($row['crossref_page'] !== '' ? ": " . $row['crossref_page'] : '') . " (" . $row['crossref_year'] . ")";
        $cited = "page " . $ref['page'];
        foreach($c['checks'] as $k) if(preg_match('/^page=\d+-\d+ not in /', $k)) $cited = "pages " . $ref['page'] . "-" . $ref['page_end']; // a cited range past the article's end (Nov 19, 2026)
        $agree = $this->agreed_text($c['checks']); // e.g. "Journal, volume and year agree": only what was really compared and agreed
        $unchecked = $this->unchecked_text($ref, $c['checks']) . $this->author_note($ref, $row, $c); // e.g. " The year couldn't be checked: no year could be read from the citation."
        $year_note = in_array('year=next to it', $c['checks']) ? " The cited year ({$ref['year']}) is one off CrossRef's ({$row['crossref_year']}), which is common (cover date vs. publication date)." : '';
        if($c['status'] == 'matched') {
            return "$agree, and $cited is in $cand, \"" . mb_substr($row['crossref_title'], 0, 120) . "\"." . $year_note . $unchecked
                 . " Accept, unless the article clearly isn't the one cited.";
        }
        if($c['status'] == 'possible') {
            $checks = implode(' ', $c['checks']);
            if(($c['via'] ?? '') === 'author and title') // AUTHOR/TITLE: no volume or pages in the citation
                $n = "$agree, but no volume or pages could be read from the citation (a book, or a citation style without them), so the numbers couldn't be compared. Check that this is the work cited (another edition or a reprint has its own DOI).";
            elseif(($c['via'] ?? '') === 'typo') { // TYPO (Nov 19, 2026): what differs, cited vs CrossRef
                $d = array();
                if(in_array('journal=differs', $c['checks'])) $d[] = "the journal (cited \"{$ref['journal']}\", CrossRef \"{$row['crossref_journal']}\")";
                if(in_array('volume=differs', $c['checks'])) $d[] = "the volume (cited {$ref['volume']}, CrossRef {$row['crossref_volume']})";
                if(in_array('issue=differs', $c['checks'])) $d[] = "the issue (cited {$ref['issue']}, CrossRef {$row['crossref_issue']})";
                foreach($c['checks'] as $k) if(str_starts_with($k, 'page=') && str_contains($k, ' not in ')) $d[] = "the page (cited {$ref['page']}, CrossRef {$row['crossref_page']})";
                $n = "$agree, but " . implode(' and ', $d) . " differ: the citation may have a typo, or use the journal's old name. Check that this is the article cited.";
            }
            elseif(str_contains($checks, 'end unknown'))   $n = "$agree, but CrossRef gives only the first page ({$row['crossref_page']}), not the last. Check that the article runs to $cited.";
            elseif($ref['page'] === '')               $n = "$agree, but no page could be read from the citation. Check that this is the article cited.";
            elseif(str_contains($checks, 'page=first page') || str_contains($checks, 'page=in ')) $n = "$agree, and $cited is in the article's pages ({$row['crossref_page']}).";
            elseif($row['crossref_page'] === '')      $n = "$agree, but CrossRef has no page numbers for this article. Check that it contains $cited.";
            else                                      $n = "$agree, but the page couldn't be confirmed (CrossRef gives \"{$row['crossref_page']}\"). Check that the article contains $cited.";
            if(in_array('journal=unknown', $c['checks']) && $row['crossref_journal'] !== '' && !in_array('journal=in the citation', $c['checks'])) $n .= " The journal name couldn't be compared: check it is \"{$row['crossref_journal']}\".";
            if(in_array('volume=not in CrossRef', $c['checks'])) $n .= " CrossRef has no volume number for this record, so volume {$ref['volume']} couldn't be compared.";
            return $n . $unchecked . " Candidate: $cand, \"" . mb_substr($row['crossref_title'], 0, 120) . "\"." . $year_note;
        }
        // not found: why the closest candidate was rejected
        $closest = " Closest CrossRef record: $cand, DOI " . $row['crossref_DOI'] . " -- not the one cited.";
        switch($c['why']) {
            case 'no volume':     $n = "No volume and page could be read from the citation (a book, or an unusual format), so no CrossRef record can be confirmed."; $closest = ''; break;
            case 'journal':       $n = "None of CrossRef's top 5 candidates are from \"{$ref['journal']}\": this journal may not have DOIs in CrossRef."; break;
            case 'not an article':$n = "CrossRef's best candidate is a whole issue or volume, not an article; the article itself wasn't among its top 5 candidates."; break;
            case 'volume':        $n = "CrossRef's top 5 candidates include \"{$row['crossref_journal']}\", but none from volume {$ref['volume']}."; break;
            case 'issue':         $n = "CrossRef's top 5 candidates include \"{$row['crossref_journal']}\" volume {$ref['volume']}, but none from issue {$ref['issue']} with this page."; break;
            case 'year':          $n = "CrossRef's closest candidate is from a different year."; break;
            case 'numbers only':  $n = "$agree, but the citation has no author or title, so only the numbers could be compared, and " . ($c['doubt'] ?? '') . ": too little to confirm a DOI."; break;
            case 'no CrossRef volume': $n = "$agree, but CrossRef has no volume number for its closest record, and its first author and title couldn't both be confirmed."; break;
            case 'page':          $n = "$agree, but $cited isn't in the article's pages ({$row['crossref_page']}): the article cited may not be in CrossRef."; break;
            case 'author and title': // AUTHOR/TITLE
                $n = "$agree, but it's another work: the authors differ (the citation has \"{$ref['author']}\", CrossRef has \"{$row['crossref_authors']}\")"
                   . " and CrossRef's title isn't in the citation."; break;
            default:              $n = "No CrossRef record agrees with this citation.";
        }
        return $n . $closest . $this->note_old_work($ref);
    }
    private function author_note($ref, $row, $c)
    {   // AUTHOR/TITLE: what the author check found, when it matters; and another record for the same pages (always)
        $n = '';
        foreach($c['checks'] as $k) {
            if(str_starts_with($k, 'author=nearly')) $n .= " The first author is spelled slightly differently in CrossRef (\"" . substr($k, 15, -1) . "\" for \"{$ref['author']}\").";
            if($k === 'author=differs') $n .= " The authors differ: the citation has \"{$ref['author']}\", CrossRef has \"{$row['crossref_authors']}\". Check that this is the work cited.";
        }
        if(!empty($c['also'])) $n .= " CrossRef also has " . implode(' and ', $c['also']) . " for the same journal, volume and first page: possibly the same work registered twice. Check which DOI to use.";
        return $n;
    }
    private function agreed_text($checks)
    {   // "Journal, volume and year agree", from the checks that really agreed (a year one off counts: the note says so)
        $names = array();
        foreach(array('journal', 'volume', 'issue', 'year') as $k)
            if(in_array("$k=OK", $checks) || ($k == 'year' && in_array('year=next to it', $checks)) || ($k == 'journal' && in_array('journal=in the citation', $checks))) $names[] = $k;
        if(in_array('author=OK', $checks)) $names[] = 'first author'; // AUTHOR/TITLE
        foreach($checks as $k) if(str_starts_with($k, 'title=OK')) $names[] = 'title'; // AUTHOR/TITLE
        if(!$names) return "Nothing could be compared";
        $last = array_pop($names);
        return ucfirst($names ? implode(', ', $names) . " and $last agree" : "$last agrees");
    }
    private function unchecked_text($ref, $checks)
    {   // the year, when it couldn't be compared, so a note never implies it was checked
        foreach($checks as $c) if(str_starts_with($c, 'year=')) return '';
        return $ref['year'] === '' ? " The year couldn't be checked: no year could be read from the citation." : " The year couldn't be checked: CrossRef has no year for this record.";
    }
    private static function words($s)
    {   // lowercase words of 3+ letters, accents removed, e.g. "Ann. Missouri Bot. Gard." -> ann, missouri, bot, gard
        $s = mb_strtolower((string) $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if($t !== false) $s = $t;
        preg_match_all('/[a-z]{3,}/', $s, $m);
        return array_values(array_diff(array_unique($m[0]), array('the', 'and', 'ser', 'des', 'der', 'del', 'for', 'von', 'und', 'zur', 'van')));
    }
    private function write_guide($out_file, $in_file, $start, $last_row, $done, $counts, $changed)
    {   // a plain-text guide next to the report, for the Data Managers
        $guide = preg_replace('/\.tsv$/', '_guide.txt', $out_file);
        $c = $counts;
        $prep = '';
        $at = $this->author_title_checks // AUTHOR/TITLE: said in the guide either way
            ? "  ON for this report. The first author's surname read from the citation (parsed_author) is compared with CrossRef's
  authors (crossref_authors), and CrossRef's title words with the citation:
  - 'checks' shows author=OK / nearly (a small spelling difference, e.g. a scanning error) / differs / unknown, and
    title=OK / partly / not in citation (with the share of CrossRef's title words found in the citation) / unknown.
  - They break ties between candidates whose journal, volume, year and page agree equally.
  - A candidate whose numbers all agree but whose authors clearly differ is 'possible', not 'matched', with a note.
  - Only when BOTH disagree -- the authors differ AND under 30% of CrossRef's title is in the citation -- is a candidate
    rejected ('not found'), e.g. a fish paper whose volume and page happen to match a paper on chickens. One alone is never
    enough: CrossRef's author and title data for old works is often incomplete or garbled, and many citations have no
    title. 'title=not in citation' is not a warning by itself.
  - No volume or pages in the citation (a book, or a style without them, e.g. Vancouver '... Journal. 1917 May'):
    a candidate can still be 'possible' (never 'matched') when nothing differs, the first author agrees (or nearly), at
    least 90% of CrossRef's title is in the citation, and, for a journal article, the journal is named in the citation.
    E.g. old books with DOIs from the Biodiversity Heritage Library (10.5962/bhl.title...). Check the edition.
  - The authors come with CrossRef's search answer, at no extra call. (For a citation searched before Nov 22, 2026, they come from
    each candidate's own CrossRef record, cached.)
"
            : "  OFF for this report: only journal, volume, issue, year and page were compared (see HOW IT WAS DONE).
";
        $dc = $this->datacite_search // DATACITE: said in the guide either way
            ? "  ON for this report. Rows CrossRef couldn't confirm, where no volume or pages could be read (datasets, reports, theses,
  books), were also searched in DataCite (https://datacite.org), which registers DOIs CrossRef doesn't have, e.g. PANGAEA,
  GBIF and Zenodo datasets (10.1594/..., 10.15468/..., 10.5281/...). A DataCite record is given only when at least 90% of
  its title is in the citation, the first author agrees (or nearly), and the year is within 1 (a cited year inside its title,
  e.g. '... in winter 1995', is allowed). It is at most 'possible'; DOI_source says 'DataCite'. A GBIF copy of a dataset is
  named in the review_note but not given when the original is found. A DOI written in the citation that CrossRef doesn't
  have is looked up in DataCite too.
"
            : "  OFF for this report: only CrossRef was searched.
";
        foreach(self::TEXT_CHANGES as $code => $what) $prep .= str_pad(number_format($changed[$code] ?? 0), 9, ' ', STR_PAD_LEFT) . " row(s): " . wordwrap($what, 100, "\n                   ") . "\n";
        $txt = "DOIs found in CrossRef for references with only a full_reference -- guide for the Data Managers
=============================================================================================
Report : " . basename($out_file) . "
Input  : " . basename($in_file) . " (data rows $start to $last_row; $done searched)
Made   : " . date("Y-m-d H:i") . "

RESULT
  matched   : {$c['matched']}
  possible  : {$c['possible']}
  not found : {$c['not found']}
  skipped   : {$c['skipped']}" . ($this->min_year ? " (" . number_format($this->min_year_skips) . " of them published before {$this->min_year}: this run's \"min_year\", not searched;
              CrossRef has almost no DOIs for works that old. Rows with no year were searched.)" : '') . "
  error     : {$c['error']}

BEFORE THE SEARCH (pre-processing)
  Some full_reference texts were cleaned before they were sent to CrossRef. The full_reference column keeps the text
  exactly as in the input file; searched_text shows what was sent; text_changes says what was changed in that row
  (empty = nothing). These are the only changes made; nothing else in the text is touched.
$prep
AUTHOR AND TITLE CHECKS
$at
DATACITE (a second search)
$dc
HOW IT WAS DONE
  Each full_reference (as in searched_text) was searched in CrossRef (the same free-text search as
  https://apps.crossref.org/SimpleTextQuery), limited to the cited year and the years next to it. CrossRef always
  returns candidates, even wrong ones, so each of its top 5 candidates was checked against what could be read from the citation: year, journal, volume, issue and page.
  The parsed_* columns show what was read; the crossref_* columns show the candidate; 'checks' lists each comparison.

WHAT TO DO WITH EACH STATUS
  matched   -> Journal, volume, year and page all agree. The DOI is very likely right. Accept it, unless the
               review_note or the title shows it clearly isn't the work cited.
               (Checked on the Data Managers' 3,561 citations with known DOIs, Nov 2026: every 'matched' DOI was right.)
  possible  -> Journal, volume and year agree, but the page can't be confirmed (CrossRef often gives only an article's
               first page). Open the DOI and check that the article covers the cited page, then accept or reject it.
               Or (author and title checks on): no volume or pages could be read, but the first author and the title
               agree; the review_note says so. Check that it's the work (and edition) cited.
               Watch out: one article often appears for several citations of later pages in the same volume; those
               later pages may belong to a different article.
  not found -> No candidate agrees. The DOI column is left empty on purpose; the closest candidate is described in the
               review_note and crossref_* columns only so you can see why it was rejected. It is sometimes the right one
               that the citation alone can't confirm (139 of 318 on the Data Managers' list): crossref_DOI is worth a look.
               When it scored " . self::HINT_SCORE . " or more, the review_note says \"Worth a look\": about 9 in 10 such candidates were
               right on that list -- but not all, which is why the tool never fills them in as the DOI.
               Usually there is no DOI: old works (before 1900), books, and many regional journals aren't in CrossRef.
               The Biodiversity Heritage Library (biodiversitylibrary.org) may have these.
  skipped   -> Not searched: too short, only a link (e.g. an iNaturalist, GenBank or museum record), or a GenBank
               sequence title (e.g. \"... segment 4 hemagglutinin (HA) gene, complete cds\"): not citations. Or only authors and a
               year (e.g. \"Negredo et al. 2011\"): no title, journal or page to confirm a DOI with. Or an identifier with no
               spaces (e.g. \"urn:catalog:RSKM:OWLPELLET:OP-1629.0012\"), a Universal Chalcidoidea Database code, or an Australian
               Faunal Directory page. The review_note says which.
  error     -> CrossRef couldn't be reached; the search will be redone on the next run.

GOOD TO KNOW
  - Citations here usually give the page where a name was published, not the article's first page; that page is
    checked against the article's page range.
  - A cited year one off from CrossRef's is accepted (cover date vs. publication date); the review_note says so.
  - Whole-issue or whole-volume records are never accepted as the answer.
  - Journal abbreviations are compared word by word with CrossRef's journal title (e.g. \"Ann. Missouri Bot. Gard.\" =
    \"Annals of the Missouri Botanical Garden\"); a different series, e.g. \"Mem. Torrey Bot. Club\" vs
    \"Bulletin of the Torrey Botanical Club\", is rejected.
  - Only CrossRef's top 5 candidates per citation are checked. A 'not found' row can still have a DOI that the free-text
    search didn't rank high enough, especially short citations of a page deep inside a long article: if another row
    of the same journal, volume and pages was matched, that DOI may apply here too.
  - If CrossRef has another record with the same journal, volume and first page, the review_note names it: the same work
    is sometimes registered twice (e.g. once per scan). Check which DOI to use.
  - A DOI written in the citation itself (e.g. 'doi:10.1594/PANGAEA.692046') is checked in CrossRef instead of searching:
    'matched' when CrossRef has it and its title is in the citation; 'possible' when CrossRef doesn't have it (it may be
    registered elsewhere, e.g. with DataCite for PANGAEA datasets, or be mistyped) or its title isn't in the citation.
  - Nothing was changed anywhere: this is a report only.

COLUMNS
  identifier, full_reference : from the input file
  searched_text              : what was sent to CrossRef, after the pre-processing above (the same text when nothing was changed)
  text_changes               : what the pre-processing changed in this row, e.g. removed '(Ref. 3)' at the end; empty = nothing
  status, DOI                : the result; DOI only for matched and possible
  DOI_URL                    : the same DOI as a link (https://doi.org/...), to open it in a browser; empty when DOI is empty
  DOI_source                 : where the DOI was found: CrossRef, DataCite, citation (written in the citation, checked), or
                               doi column (the input file's own doi column, checked against CrossRef's record)
  review_note                : what was found, in plain words
  parsed_*                   : the first author's surname, year, journal, volume, issue and page read from the full_reference
  crossref_*                 : the CrossRef record shown (DOI, score, type, year, journal, volume, issue, page, title, and
                               its authors' surnames when the author check ran)
  checks                     : each comparison, e.g. journal=OK volume=OK year=OK page=in 305-313
";
        file_put_contents($guide, $txt);
        return $guide;
    }
}
?>
