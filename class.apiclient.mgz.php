<?php

require __DIR__ . '/vendor/autoload.php';

class MGZ_API_Client {

  public $oParams;

  public function __construct( $aParams ) {

    $this->LOGFILE = __DIR__ .'/logs/api-mgz-'.date('Ymd').'.log';
    if( !empty( $aParams['log_dir'] ) ) {
      $this->LOGFILE = $aParams['log_dir'] .'/api-mgz-'.date( 'Ymd' ).'.log';
    }

    $this->aParams = $aParams;
    //$this->aParams['api_format'] = 'application/json';

		$this->aParams['api_headers'] = array(
      'X-Auth-Api-Key'     => $this->aParams['api_key'],
      'X-Auth-Api-Secret'  => $this->aParams['api_secret'],
			//'Content-Type' => 'multipart/form-data',
		);

		$this->oAPI = new RestClient( array(
			'base_url' => $this->aParams['api_url'],
			'headers'  => $this->aParams['api_headers'],
			'logfile'  => $this->LOGFILE,
			'curl_options' => array( CURLOPT_SSL_VERIFYPEER => 0 ),//, CURLOPT_COOKIESESSION => 1],
		) );
  }

  /**
   * Generic response decode
   */
  public function response_decode( $aOptions = array() ) {
    $oResponse = json_decode( $this->lastResult->response );

    if( !in_array( $this->lastResult->info->http_code, array( 200, 201, 203, 204, 205, 206, 207, 208, 210, 226 ) ) ) {
      throw new Exception( !empty( $this->lastResult->response_status_lines[0] ) ? $this->lastResult->response_status_lines[0] : 'Code HTTP: '. $this->lastResult->info->http_code );
    }
    elseif( $oResponse->ok != 1 && ( !array_key_exists( 'bypassnok', $aOptions ) || !$aOptions['bypassnok'] ) ) {
      throw new Exception( $oResponse->message ?? 'Réponse NOK' );
    }

    return $oResponse;
  }

  /**
   * Get all formations data
   */
  public function get_formations() {

    try {
      $this->lastResult = $this->oAPI->get( 'formations' );
      $oResponse = $this->response_decode();

      $tFormations = array();
      foreach( $oResponse->formations as $oFormation ) {
        // Build precisions lists
        $oFormation->aPrecisions_lists = array();
        if( !empty( $oFormation->precisions ) ) {
          $oFormation->aPrecisions_lists = $this->precisions_lists( $oFormation->precisions, $oFormation->precisions_details );
          $oFormation->aPrecisions_allowed_values = $oFormation->aPrecisions_lists['allowed_values'];
          unset( $oFormation->aPrecisions_lists['allowed_values'] );
        }

        // Index array by ID
        $tFormations[trim( $oFormation->id )] = $oFormation;
      }

      return $tFormations;
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la récupération des formations disponibles',
      );
    }

  }

  /**
   * Manipulate precisions array to lists precisions of each type
   * For each value of a precision, lists allowed options in other precisions
   *
   * @param  array   $tPrecisions précisions array as returned in a particular formation in get_formations() output
   * @param  array   $tDetails    précisions details array as returned in a particular formation in get_formations() output
   * @return array                [
   *                                allowed_values  => array of all allowed values, // useful to filter user input
   *                                precision_key   => [
   *                                  <value> => [
   *                                    nom         => string,
   *                                    description => string,
   *                                    allowed     => [
   *                                      another_precision_key => array of values allowed for another_precision_key when this precision_key has value <value>,
   *                                      ...
   *                                    ]
   *                                  ],
   *                                  ...
   *                                ]
   *                              ]
   */
  public function precisions_lists( $tPrecisions, $tDetails ) {

    $tDetails = (array) $tDetails;

    $tLists = array_fill_keys( array_keys( $tDetails ), array() );
    $tLists['allowed_values'] = array();

    foreach( $tPrecisions as $oPrecisions_possibles ) {
      foreach( (array) $oPrecisions_possibles as $prec => $prec_id ) {
        if( !in_array( $prec_id, $tLists['allowed_values']) ) {
          $tLists['allowed_values'][] = $prec_id;
        }

        if( !isset( $tLists[$prec][$prec_id] ) ) {
          if( !array_key_exists( $prec_id, (array) $tDetails[$prec] ) ) {
            continue;
          }

          $tLists[$prec][$prec_id] = (array) $tDetails[$prec]->{$prec_id};

          if( !isset( $tLists[$prec][$prec_id]['allowed'] ) ) {
            $tLists[$prec][$prec_id]['allowed'] = array_fill_keys( array_keys( $tDetails ), array() );
            unset( $tLists[$prec][$prec_id]['allowed'][$prec] );
          }
        }

        // Builds array of possible values for each value
        foreach( $oPrecisions_possibles as $k => $v ) {
          if( $k == $prec || in_array( $v, $tLists[$prec][$prec_id]['allowed'][$k] ) ) continue;

          $tLists[$prec][$prec_id]['allowed'][$k][] = $v;
        }
      }
    }

    return $tLists;
  }

  /**
   * Is formation active for public or pro
   * @param  object  $oFormation formation object from get_formations() output
   * @param  boolean $pro        true if user is logged as pro
   * @return boolean
   */
  public function is_formation_active( $oFormation, $pro = false ) {

    if( !isset( $oFormation->resapro ) || !isset( $oFormation->resapublic ) ) {
      return false;
    }

    return $pro ? !empty( $oFormation->resapro ) : !empty( $oFormation->resapublic );

  }

  /**
   * Get formules for a provided formation and precisions
   *
   * @param  int $formation_id    formation identifier
   * @param  array $tPrecisions   ['categorie_id' => <id>, 'vehicule_id' => <id>, 'boite_id' => <id>, 'filiere_id' => <id>]
   * @return [type]               [description]
   */
  public function get_formules( $formation_id, $tPrecisions ) {

    try {
      // tPrecisions example:
      //{"precisions": {"categorie_id": "4","vehicule_id": "6","boite_id": "1","filiere_id": "0"}}

      $this->lastResult = $this->oAPI->post( 'formation/'. $formation_id .'/formules', json_encode( $tPrecisions ) );
      $oResponse = $this->response_decode();
      //trace($oResponse);
      $oFormules = $oResponse->produits;

      $tFormules = array();
      foreach( $oFormules as $oFormule ) {
        if( !empty( $oFormule->options ) ) {
          $tOptions = array();
          foreach( $oFormule->options as $oOption ) {
            $tOptions[$oOption->id] = $oOption;
          }
          $oFormule->options = $tOptions;
        }
        $tFormules[trim( $oFormule->id )] = $oFormule;
      }

      return $tFormules;
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la récupération des formules disponibles',
      );
    }

  }

  /**
   * Get sessions
   *
   * @param  string $formule_id
   * @param  int $debut         timestamp required if $mode is 'bydate'
   * @param  int $fin           timestamp required if $mode is 'bydate'
   * @param  string $mode       'bydate' or 'byid'
   * @return array
   */
  public function get_sessions( $formule_id, $tPrecisions, $debut, $fin, $mode = 'bydate' ) {

    try {
      $this->lastResult = $this->oAPI->get( 'sessions/'. $formule_id, json_encode( array( 'periode' => array( 'debut' => $debut, 'fin' => $fin ), 'precisions' => $tPrecisions ) ) );
      $oResponse = $this->response_decode();

      //trace($oResponse);
      $tDispos = array();

      foreach( $oResponse->sessions as $oDispo ) {
        // Elimine les sessions dans le nuage antérieures à la date de début de période (les sessions dans le nuage depuis le début de la semaine sont retournées)
        if( $oDispo->debut < $debut ) {
          continue;
        }

        $oDispo->formule_id = $formule_id;

        $oDispo->placesdispo = 100;
        $oDispo->placestotal = 100;
        $oDispo->horaires = array();

        foreach( $oDispo->planning as &$oActivite ) {
          $oActivite->debut = $oActivite->debut;
          $oActivite->fin = $oActivite->fin;

          // Places dispo dans le stage = places dispo dans l'activité du stage ayant le moins de places disponibles
          $oDispo->placesdispo = min( $oDispo->placesdispo, $oActivite->placesdispo );
          $oDispo->placestotal = min( $oDispo->placestotal, $oActivite->placestotal );
          $oDispo->horaires[] = array( 'debut' => $oActivite->debut, 'fin' => $oActivite->fin );
        }
        unset( $oActivite ); // libere la variable passée par reference

        if( $mode == 'byid' ) {
          $tDispos[$oDispo->id] = $oDispo;
        }
        // by date
        else {
          // Plusieurs dispos peuvent avoir lieu la même date de début: 1 to many
          if( !array_key_exists( $oDispo->debut, $tDispos ) ) {
            $tDispos[$oDispo->debut] = array();
          }

          $tDispos[$oDispo->debut][trim( $oDispo->id )] = $oDispo;
        }
      }

      // On ordonne les sessions de chaque date de façon à remplir les stages au mieux :
      // on classe par proportions de places dispos croissantes
      // puis on classe par nombre de places totale décroissantes
      // TODO si le nombre d'enseignant par dispo est ajouté à l'avenir,
      // prévoir un mode de tri où le calcul tient compte de ce nombre
      // et laisser le choix entre maximum d'enseignant occupés,
      // groupes les plus grands et minimum d'enseignant occupés
      foreach( $tDispos as $debut => $tDispos_med ) {
        $tProp_dispos = array();
        $tPlaces_totales = array();

        foreach( $tDispos_med as $dispoid => $oDispo ) {
          $tProp_dispos[$dispoid] = !empty( $oDispo->placestotal ) ? $oDispo->placesdispo / $oDispo->placestotal : 1 ;
          $tPlaces_totales[$dispoid] = $oDispo->placestotal;
        }

        // Trie les données par props dispos croissantes, totales décroissant
        // Ajoute $tDispos_med en tant que dernier paramètre, pour trier par la clé commune
        // @see https://www.php.net/manual/fr/function.array-multisort.php
        array_multisort( $tProp_dispos, SORT_ASC, $tPlaces_totales, SORT_DESC, $tDispos_med );

        $tDispos[$debut] = $tDispos_med;
      }

      // Ordonner par date
      ksort( $tDispos );

      return $tDispos;
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la récupération des sessions',
      );
    }

  }

  /**
   * Get session
   *
   * @param  string $session_id
   * @return array
   */
  public function get_session( $session_id ) {

    try {
      $this->lastResult = $this->oAPI->get( 'session/'. $session_id );
      $oResponse = $this->response_decode();

      //trace($oResponse);
      $oRaw_dispo = $oResponse->session;
      $oDispo = null;

      $oRaw_dispo->placesdispo = 100;
      $oRaw_dispo->placestotal = 100;
      $oRaw_dispo->horaires = array();

      foreach( $oRaw_dispo->planning as &$oActivite ) {
        $oActivite->debut = $oActivite->debut;
        $oActivite->fin = $oActivite->fin;

        $oRaw_dispo->placesdispo = min( $oRaw_dispo->placesdispo, $oActivite->placesdispo );
        $oRaw_dispo->placestotal = min( $oRaw_dispo->placestotal, $oActivite->placestotal );
        $oRaw_dispo->horaires[] = array( 'debut' => $oActivite->debut, 'fin' => $oActivite->fin );
      }
      unset($oActivite);

      $oDispo = $oRaw_dispo;

      return $oDispo;
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la récupération d’une sessions',
      );
    }

  }

  /**
   * Create or update a lock
   *
   * @param string  $session_id identifiant de session
   * @param string  $blocage_id optionnel. identifiant de verrou pour le renouveler
   * @param integer $nb_places  nombre de places que le verrou doit bloquer
   * @param integer $duree      durée de validité du verrou
   */
  public function set_blocage( $session_id, $blocage_id = null, $nb_places = 1, $duree = 15 ) {

    $tData = array(
      'nbplaces'    => $nb_places,
      'dureelock'   => $duree,
    );

    if( !empty( $blocage_id ) ) {
      $tData['blocage_id'] = $blocage_id;
    }

    try {
      $this->lastResult = $this->oAPI->post( 'blocage/'. $session_id, json_encode( $tData ) );
      $oResponse = $this->response_decode( array( 'bypassnok' => false ) );

      return $oResponse->blocage;
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la création du verrou',
      );
    }

  }

  /**
   * Delete a lock
   *
   * @param  string $blocage_id identifiant de verrou
   * @return boolean
   */
  public function del_blocage( $blocage_id ) {

    try {
      $this->lastResult = $this->oAPI->delete( 'blocage/'. $blocage_id );
      $oResponse = $this->response_decode( array( 'bypassnok' => false ) );

      return $oResponse->result; // true or false
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la suppression du verrou',
      );
    }

  }

  /**
   * Récupère les détails d'un code promo
   *
   * @param  string $codepromo
   * @return object              détails d'application du code promo
   */
  public function get_promo( $codepromo ) {

    try {
      $this->lastResult = $this->oAPI->get( 'promo/'. $codepromo );
      $oResponse = $this->response_decode( array( 'bypassnok' => false ) );

      $oPromo = $oResponse->promo;

      // Code non validé: false
      if( !empty( $oPromo ) ) {
        // Re-index details
        foreach( array( 'formation', 'filiere', 'categorie', 'boite', 'vehicule', 'produit' ) as $prec ) {
          if( !empty( $oPromo->details ) && !empty( $oPromo->details->{$prec} ) && is_array( $oPromo->details->{$prec} ) ) {
            // On indexe les produits par id
            $aPrecs = array();
            foreach( $oPromo->details->{$prec} as $oPrec ) {
              $aPrecs[$oPrec->id] = $oPrec;
            }

            $oPromo->details->{$prec} = $aPrecs;
          }
        }
      }
      else {
        $oPromo = new StdClass;
      }

      return $oPromo;
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la récupération d’un code promo',
      );
    }

  }

  /**
   * Envoi les données des réservations et récupère une URL de paiement en ligne
   *
   * @param  array $tData
   * @return mixed        URL de paiement ou array d'erreur
   */
  public function post_eleve( $tData ) {

    /**
     * Exemple
     */
    /*
    Array
    (
      [facturation] => Array
      (
        [userpayeur] => 0
        [userfacturationtitle] => Commande en ligne
        [userfacturationorganization] => Special Forces Ltd
        [userfacturationsurname] => General
        [userfacturationname] => Mendes
        [userfacturationaddress] => 102 boulevard Cazasombra 75010 Paris
        [userfacturationemail] => dutch@specialforces.gov.us
      )
      [codepromo] =>
      [eleves] => Array
      (
        [0] => Array
        (
          [formation_id] => 9
          [precisions] => Array
          (
            [categorie] => 4
            [vehicule] => 6
            [boite] => 2
          )
          [formule] => Array
          (
            [0] => Array
            (
              [formule_id] => 43
              [options] => Array
              (
                [0] => 72
              )
              [session_id] => 60213effc8ac1
              [lock_id] => cf02aa8143ec67196ee209e298a75e904036f42bff113d7c9d3c45896d05b42e
            )
          )
          [eleve] => Array
          (
            [usergenre] => M
            [username] => Arnold
            [usersurname] => Dutch
            [userdatenaissance] => 18/05/1979
            [usernville] => Paris
            [usernpays] => FR
            [useradressenovoie] => 18
            [useradresseextension] =>
            [useradressetypevoie] => rue
            [useradressenomvoie] => du Predator
            [useradressecomplement] =>
            [userzip] => 34000
            [usercity] => Montpellier
            [useremail] => arnold.dutch@predator.com
            [usertelport] => 0612345678
            [userpermisneph] => 1234567890
          )
          [permisobtenus] => Array
          (
            [0] => Array
            (
              [categorie] => B
              [dateobtention] => 15/06/2004
            )
          )
        )
      )
      [urls] => Array
      (
        [retourok] => https://votredomaine.fr/reservation-en-ligne/etape-1/?reussite&token=abc
        [retournok] => https://votredomaine.fr/reservation-en-ligne/etape-1/?echec&token=abc
        [hookok] => https://votredomaine.fr?hook=zbooking&result=ok&token=abc
        [hooknok] => https://votredomaine.fr?hook=zbooking&result=nok&token=abc
      )
    )
    */

    try {
      $this->lastResult = $this->oAPI->post( 'eleve/', json_encode( $tData ) );
      $oResponse = $this->response_decode();

      return $oResponse->url; // URL
    }
    catch( Exception $e ) {
      return array(
        'ok'    => 0,
        'code'  => $e->getMessage(),
        'msg'   => 'Erreur à la l’enregistrement des réservations',
      );
    }

  }

}
