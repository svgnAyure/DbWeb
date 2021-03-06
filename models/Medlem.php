<?php

// Includes
include_once "utils/database.php";
include_once "utils/cache.php";

// Klasse som representerer klubbens medlemmer
class Medlem {

  // Medlemsobjekter opprettes direkte fra $_POST ved registrering, eller fra
  // et assosiativt array som hentes fra eksisterende oppføringer i databasen.
  // Ulike objektvariabler benyttes basert på hvor dataene kommer fra.
  public function __construct($medlem = [], $fraDatabase = false) {
    $this->medlemsnummer = $fraDatabase ? $medlem["medlemsnummer"] : null;
    $this->fornavn       = $medlem["fornavn"];
    $this->etternavn     = $medlem["etternavn"];
    $this->adresse       = $medlem["adresse"];
    $this->postnummer    = $medlem["postnummer"];
    $this->poststed      = $fraDatabase ? $medlem["poststed"] : null;
    $this->telefonnummer = $medlem["telefonnummer"];
    $this->epost         = $medlem["epost"];
    $this->passord       = $fraDatabase ? null : $medlem["passord"];
    $this->passord2      = $fraDatabase ? null : $medlem["passord2"];
  }

  // Metode for validering av felter.
  // Utføres kun når medlemsobjektet konstrueres fra $_POST ved registrering,
  // altså stoler vi på dataene dersom de kommer direkte fra databasen.
  private function valider($validerPassord) {
    $feil = [];

    // Fornavn kan bestå av bokstaver, bindestrek, apostrof og punktum. Maks 100 tegn.
    if (!preg_match("/^[a-zæøåÆØÅ '.-]{1,100}$/i", $this->fornavn))
      $feil["fornavn"] = "Ugyldig fornavn.";

    // Etternavn kan bestå av bokstaver, bindestrek, apostrof og punktum. Maks 100 tegn.
    if (!preg_match("/^[a-zæøåÆØÅ '.-]{1,100}$/i", $this->etternavn))
      $feil["etternavn"] = "Ugyldig etternavn.";

    // Adressen kan kun bestå av bokstaver, tall, bindestrek, apostrof, komma og punktum. Maks 100 tegn.
    if (!preg_match("/^[\wæøåÆØÅ '.,-]{1,100}$/i", $this->adresse))
      $feil["adresse"] = "Ugyldig adresse.";

    // Postnummeret må bestå av 4 siffer
    if (!preg_match("/^\d{4}$/", $this->postnummer))
      $feil["postnummer"] = "Ugyldig postnummer.";

    // Telefonnummer må bestå av 8 siffer. Vi begrenser oss altså til norske telefonnummer.
    if (!preg_match("/^\d{8}$/", $this->telefonnummer))
      $feil["telefonnummer"] = "Ugyldig telefonnummer.";

    // Bruker PHP sitt innebygde filter for å validere e-postadresse. Maks 100 tegn.
    if (!filter_var($this->epost, FILTER_VALIDATE_EMAIL) || strlen($this->epost) > 100)
      $feil["epost"] = "Ugyldig e-postadresse.";

    // Dersom passordet skal valideres, f.eks. ved passordbytte, utføres også dette.
    if ($validerPassord) {

      // Passordet må inneholde store- og små bokstaver og tall. Minst 8 tegn.
      if (!preg_match("/(?=.*\d)(?=.*[a-zæøå])(?=.*[A-ZÆØÅ]).{8,}/", $this->passord))
        $feil["passord"] = "Passordet må bestå av minst 8 tegn og inneholde både tall, store-, og små bokstaver.";

      // Bruker må oppgi passord to ganger for å sikre at han/hun ikke har tastet feil
      if ($this->passord !== $this->passord2)
        $feil["passord2"] = "Passordene må være like.";

      // Dersom valideringene er godkjent, krypter passord og fjern ukryptert passord fra objekt.
      $this->passord = password_hash($this->passord, PASSWORD_BCRYPT);
      $this->passord2 = null;
    }

    // Dersom noen av valideringene feilet, kast unntak og send med forklaringer.
      if (!empty($feil))
        throw new InvalidArgumentException(json_encode($feil));
  }


  // Metode for å lagre et medlemsobjekt til databasen.
  public function lagre() {

    // UPDATE-spørring dersom medlemsnummer finnes, INSERT-spørring ellers.
    // Dersom både medlemsnummer og passord finnes på objektet, skal passordet endres.
    try {
      if ($this->medlemsnummer) {
        if ($this->passord) {
          $this->valider(true);
          $this->oppdaterPassord();
        } else {
          $this->valider(false);
          $this->oppdater();
        }
      }
      else {
        $this->valider(true);
        $this->settInn();
      }
    }

    // Feilkode 1062 - brudd på UNIQUE i databasen - e-postadressen eksisterer.
    // Feilkode 1452 - brudd på fremmednøkkelkrav i databasen - ugyldig postnummer.
    // Kaster unntaket videre dersom det ikke er relatert til validering.
    catch (mysqli_sql_exception $e) {
      if ($e->getCode() == 1062)
        throw new InvalidArgumentException(json_encode(["epost" => "E-postadressen er allerede i bruk."]));
      if ($e->getCode() == 1452)
        throw new InvalidArgumentException(json_encode(["postnummer" => "Ugyldig postnummer."]));
      throw $e;
    }
  }


  // Metode for innsetting av nye medlemmer.
  private function settInn() {

    // SQL-spørring med parametre for bruk i prepared statement.
    $sql = "
      INSERT INTO medlem (fornavn, etternavn, adresse, postnummer, telefonnummer, epost, passord)
      VALUES (?, ?, ?, ?, ?, ?, ?);
    ";

    // Verdiene som skal settes inn i databasen.
    $verdier = [
      $this->fornavn,
      $this->etternavn,
      $this->adresse,
      $this->postnummer,
      $this->telefonnummer,
      $this->epost,
      $this->passord
    ];

    // Kobler til databasen og utfører spørringen.
    $con = new Database();
    $res = $con->spørring($sql, $verdier);

    // Henter medlemsnummer fra nyinnsatt rad og fjerner passordet fra objektet.
    $this->medlemsnummer = $res->insert_id;
    $this->passord = null;
  }


  // Metode for oppdatering av eksisterende medlemmer.
  private function oppdater() {

    // SQL-spørring med parametre for bruk i prepared statement.
    $sql = "
      UPDATE medlem
      SET
        fornavn = ?,
        etternavn = ?,
        adresse = ?,
        postnummer = ?,
        telefonnummer = ?,
        epost = ?
      WHERE
        medlemsnummer = ?
    ";

    // Verdiene som skal settes inn i databasen.
    $verdier = [
      $this->fornavn,
      $this->etternavn,
      $this->adresse,
      $this->postnummer,
      $this->telefonnummer,
      $this->epost,
      $this->medlemsnummer
    ];

    // Kobler til databasen og utfører spørringen.
    $con = new Database();
    $con->spørring($sql, $verdier);
  }


  // Metode for oppdatering av passord.
  private function oppdaterPassord() {

    // SQL-spørring med parametre for bruk i prepared statement.
    $sql = "
      UPDATE medlem
      SET passord = ?
      WHERE medlemsnummer = ?;
    ";

    // Kobler til databasen og utfører spørringen.
    $con = new Database();
    $con->spørring($sql, [$this->passord, $this->medlemsnummer]);

    // Fjerner det krypterte passordet fra objektet.
    $this->passord = null;
  }


  // Statisk metode for sletting av medlemmer fra databasen.
  public static function slett($medlemsnummer) {

    // Slett fra cache hvis objektet finnes.
    Cache::set("medlem", $medlemsnummer, null);

    // Kaller på en lagret prosedyre. Bruker prepared statement.
    $sql = "
      CALL slett_medlem(?);
    ";

    // Kobler til databasen og utfører spørringen.
    $con = new Database();
    $con->spørring($sql, [$medlemsnummer]);
  }


  // Metode for å slette et medlem fra databasen.
  public function slettDenne($medlemsnummer) {
    self::slett($this->medlemsnummer);
  }


  // Statisk metode for å finne et medlem basert på gitt medlemsnummer.
  public static function finn($medlemsnummer) {

    // Returnerer medlem fra cache hvis det finnes der.
    if ($medlem = Cache::get("medlem", $medlemsnummer))
      return $medlem;

    // SQL-spørring med parametre for bruk i prepared statement.
    $sql = "
      SELECT
        m.medlemsnummer,
        m.fornavn,
        m.etternavn,
        m.adresse,
        p.postnummer,
        p.poststed,
        m.telefonnummer,
        m.epost
      FROM
        medlem AS m,
        poststed AS p
      WHERE
        m.postnummer = p.postnummer AND
        medlemsnummer = ?;
    ";

    // Kobler til databasen og utfører spørringen.
    // Henter resultatet fra spørringen i et assosiativt array ($res).
    $con = new Database();
    $res = $con
      ->spørring($sql, [$medlemsnummer])
      ->get_result()
      ->fetch_assoc();

    // Oppretter nytt medlemsobjekt, lagrer i cache og returnerer.
    $medlem = $res ? new Medlem($res, true) : null;
    return Cache::set("medlem", $medlemsnummer, $medlem);
  }


  // Statisk metode som lister opp alle medlemmer i databasen.
  public static function finnAlle() {

    // SQL-spørring for uthenting av alle medlemmer.
    $sql = "
    SELECT
      m.medlemsnummer,
      m.fornavn,
      m.etternavn,
      m.adresse,
      p.postnummer,
      p.poststed,
      m.telefonnummer,
      m.epost
    FROM
      medlem AS m,
      poststed AS p
    WHERE
      m.postnummer = p.postnummer
    ORDER BY
      m.etternavn ASC, m.fornavn ASC;
    ";

    // Kobler til databasen og utfører spørringen.
    // Henter resultatet fra spørringen i et assosiativt array ($res).
    $con = new Database();
    $res = $con
      ->spørring($sql)
      ->get_result()
      ->fetch_all(MYSQLI_ASSOC);

    // Returnerer et array av idrettsobjekter.
    return array_map(function($rad) {
      return Cache::set("medlem", $rad["medlemsnummer"], new Medlem($rad, true));
    }, $res);
  }


  // Statisk metode for autentisering av brukere ved innlogging.
  public static function autentiser($identifikator, $passord) {

    // SQL-spørring med parametre for bruk i prepared statement.
    $sql = "
      SELECT m.medlemsnummer, m.passord, (a.medlemsnummer IS NOT NULL) AS administrator
      FROM medlem AS m LEFT OUTER JOIN administrator AS a ON m.medlemsnummer = a.medlemsnummer
      WHERE m.medlemsnummer = ? OR m.epost = ?;
    ";

    // Kobler til databasen og utfører spørringen.
    // Henter resultatet fra spørringen i et assosiativt array ($res).
    $con = new Database();
    $res = $con
      ->spørring($sql, [$identifikator, $identifikator])
      ->get_result()
      ->fetch_assoc();

    // Verifiserer passordet ved å sammenlikne brukerinput og hash fra databasen.
    if (password_verify($passord, $res["passord"]))
      return ["medlemsnummer" => $res["medlemsnummer"], "administrator" => (bool) $res["administrator"]];

    // Kast unntak dersom autentiseringen feilet og gi passende tilbakemelding.
    throw new InvalidArgumentException(json_encode(["autentisering" => "Autentisering feilet."]));
  }


  // Returnerer hele navnet til medlemmet.
  public function fulltNavn($etternavnFørst = false) {
    return $etternavnFørst
      ? "$this->etternavn, $this->fornavn"
      : "$this->fornavn $this->etternavn";
  }


  // Returnerer adressen med postnummer og sted.
  public function fullAdresse() {
    return "$this->adresse, $this->postnummer $this->poststed";
  }

}

?>
