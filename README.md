# MM Optimizer

Plugin WordPress di ottimizzazione immagini. Rileva le librerie grafiche
disponibili, verifica quali funzionano davvero e propone le impostazioni
ottimali per quel server specifico.

## Perche' esiste

I plugin di ottimizzazione esistenti hanno due difetti ricorrenti.

Il primo e' che **si fidano del supporto dichiarato**. `Imagick::queryFormats()`
elenca `WEBP` anche quando il delegato e' rotto; un `cwebp` nel `PATH` puo'
essere uno script segnaposto. Il risultato sono file corrotti scritti in
silenzio, che ci si accorge di avere settimane dopo.

Il secondo e' che **scaricano la decisione sull'utente**: decine di interruttori
e cursori senza alcuna indicazione su quali abbiano senso su quell'host.

MM Optimizer risponde a entrambi con un motore di rilevamento che prova davvero
a codificare, e con un motore di raccomandazione che traduce l'esito in una
configurazione motivata.

## Architettura

```
mm-optimizer.php          bootstrap: costanti, elenco require, agganci di attivazione
includes/
  class-base.php          function_exists() consapevole di disable_functions e suhosin,
                          is_file() consapevole di open_basedir, MIME dai magic bytes,
                          percorsi relativizzati per sopravvivere ai traslochi
  class-options.php       opzione unica serializzata, lettura a notazione puntata,
                          sanificazione a whitelist rigorosa
  class-presets.php       i quattro livelli di ottimizzazione
  class-log.php           registro diagnostico a dimensione limitata
  class-conflicts.php     tre segnali di rilevamento, matrice di ritiro
  class-probe.php         la prova funzionale ("blind test")
  class-capabilities.php  il motore di rilevamento
  class-recommender.php   dalle capacita' alle impostazioni, con le motivazioni
  class-plugin.php        singleton, agganci, schema del database
  api.php                 API pubblica nello spazio dei nomi globale
admin/
  class-admin.php         menu, risorse, avvisi
  class-settings.php      pagina a schede, resa a mano
  class-report.php        matrice delle capacita' e configurazione consigliata
  class-ajax.php          rilevamento e applicazione della configurazione
assets/probe/             campioni di prova deterministici
```

## Il rilevamento in cinque fasi

1. **Ambiente** — hosting gestito (costanti note), disponibilita' di `exec`,
   `open_basedir`, memoria, tempo massimo. Su un hosting gestito la ricerca dei
   binari viene saltata del tutto: non sarebbero eseguibili comunque.
2. **Supporto dichiarato** — `gd_info()`, `Imagick::queryFormats()`,
   `Gmagick::queryFormats()`. Registrato come `claimed`, mai come `verified`.
3. **Prova funzionale** — per ogni coppia (libreria, formato) codifica
   `assets/probe/probe.png`, poi verifica: il file esiste, non e' vuoto, i magic
   bytes corrispondono, la dimensione sta sotto un tetto noto, e il risultato e'
   rileggibile. Solo allora `verified = true`.
4. **Binari di sistema** — cercati in cartelle esplicite, mai risolti da una
   shell, ogni `is_file()` filtrato da `open_basedir`. Gli ottimizzatori senza
   perdita sono provati su un JPEG volutamente gonfio di metadati: un binario
   che non fa nulla non riesce a ridurlo.
5. **Classifica** — i codificatori verificati vengono misurati su una fotografia
   di prova da 512x384 e ordinati per byte prodotti, con i millisecondi come
   spareggio.

### Perche' due campioni diversi

"Funziona?" e "chi comprime meglio?" sono domande diverse e richiedono campioni
diversi. Sul campione da 64x64 usato per la verifica, `cwebp -m 6` risulta due
byte **piu' grande** di `-m 4`; sulla fotografia di prova e' il 7% piu' piccolo,
coerentemente con quello che fa sulle immagini reali. Classificare sul campione
sbagliato porta a scegliere il motore sbagliato.

## Invalidazione della cache

Le capacita' vivono nell'opzione `mm_optimizer_capabilities` con
`autoload = no`, non in un transient: con un object cache Redis un flush
costringerebbe a rifare il rilevamento, con le sue chiamate `proc_open`, su una
richiesta qualsiasi del front end.

L'invalidazione e' per **impronta**, non per scadenza:

```
sha1( PHP_VERSION | versione GD | imagetypes() | versione Imagick | gmagick
      | disable_functions | open_basedir | PHP_OS | versione del plugin | schema )
```

Cosi' un aggiornamento di PHP o una nuova estensione vengono colti nell'istante
in cui avvengono, senza indovinare un TTL. Fa eccezione la ricerca dei binari,
riesaminata comunque una volta a settimana: un pacchetto puo' comparire senza
che nulla dell'impronta cambi.

## Ritiro in presenza di concorrenti

Tre segnali, dal piu' economico: basename dei plugin attivi, simboli a runtime
(per cartelle rinominate), marcatori nei file `.htaccess` (per plugin
disattivati che hanno lasciato regole attive).

| Sottosistema | Con un conflitto attivo |
|---|---|
| Consegna `<picture>` e `.htaccess` | spenta, nessun aggancio registrato |
| Conversione, coda, conversione in blocco | **attiva** |
| Ottimizzazione delle miniature | spenta solo se EWWW e' attivo |
| Diagnostica e raccomandazioni | attive |

La costante `MM_OPTIMIZER_FORCE_DELIVERY` in `wp-config.php` scavalca il ritiro
per le prove, segnalandolo con un avviso.

## Agganci per gli sviluppatori

| Filtro | Scopo |
|---|---|
| `mm_optimizer_quality` | qualita' scelta per una conversione |
| `mm_optimizer_blocks` | decisione di ritiro di un sottosistema |
| `mm_optimizer_binary_paths` | cartelle in cui cercare i binari |

Funzioni pubbliche: `mm_optimizer_get_option()`, `mm_optimizer_capabilities()`,
`mm_optimizer_recommendation()`, `mm_optimizer_supports()`.

## Licenza

GPL-3.0-or-later.
