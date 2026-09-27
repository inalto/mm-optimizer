=== MM Optimizer ===
Contributors: inalto
Tags: immagini, ottimizzazione, webp, avif, prestazioni
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Rileva le librerie grafiche del server, verifica quali funzionano davvero e propone le impostazioni ottimali. Genera WebP e AVIF senza mai toccare gli originali.

== Description ==

MM Optimizer parte da una domanda che quasi nessun plugin di ottimizzazione pone: **che cosa sa fare davvero questo server?**

Invece di presentare una distesa di interruttori da indovinare, all'attivazione esegue un rilevamento completo e mostra una matrice di capacita' con l'esito di prove reali. Per ogni libreria e per ogni formato codifica un campione noto, ne controlla i byte magici, verifica che il risultato sia rileggibile e misura quanto occupa. Il supporto soltanto dichiarato non viene mai dato per buono: e' proprio quello il caso che produce file corrotti in silenzio.

Da quel rilevamento nasce una configurazione consigliata, con la motivazione accanto a ogni scelta.

= Cosa fa =

* **Rileva** GD, Imagick, Gmagick e i binari di sistema (cwebp, avifenc, jpegoptim, jpegtran, optipng, pngquant, gifsicle).
* **Verifica** ogni codificatore con una prova funzionale, non con un controllo di versione.
* **Classifica** i motori disponibili misurandoli sulla stessa fotografia di prova e sceglie quello che produce il file piu' piccolo. Nessuna tabella di preferenze scritta a mano: su un server dove cwebp batte GD, vince cwebp.
* **Propone** formati, qualita', livello e modalita' di consegna, spiegando ogni decisione.
* **Genera** derivate WebP e AVIF con quattro livelli di ottimizzazione.
* **Consegna** le immagini in modo responsive con `<picture>` e un `srcset` per formato, oppure tramite negoziazione `.htaccess`.
* **Si ritira** quando un altro ottimizzatore e' attivo, invece di sovrapporsi.

= Cosa non fa, per scelta =

* **Non modifica mai i file originali.** Genera derivate affiancate e interviene solo sulle miniature prodotte da WordPress, al momento in cui vengono create.
* Non include binari precompilati: usa quelli del sistema, se ci sono.
* Non scarica eseguibili da internet a runtime.
* Non include lazy loading, CDN, telemetria o integrazioni con gallerie di terze parti.

= Livelli di ottimizzazione =

I numeri derivano da misure reali, non da stime.

* **Conservativo** — WebP q90, AVIF q68. Qualita' massima.
* **Bilanciato** — WebP q82, AVIF q58. Circa il 45% in meno con WebP, il 66% con AVIF.
* **Aggressivo** — WebP q75, AVIF q50. Fino al 78% in meno.
* **Personalizzato** — valori a piacere.

== Frequently Asked Questions ==

= Modifica le mie immagini originali? =

No, mai. Gli originali restano identici byte per byte. Il plugin crea file affiancati (`foto.jpg.webp`, `foto.jpg.avif`) e imposta la qualita' con cui WordPress genera le miniature, cosi' che nascano gia' ottimizzate senza una seconda ricompressione.

= Posso usarlo insieme a un altro ottimizzatore? =

La conversione funziona comunque, ma la consegna resta spenta finche' l'altro plugin e' attivo: due plugin che riscrivono gli stessi URL si ostacolano a vicenda. L'avviso in bacheca indica quale plugin e' in conflitto.

= Perche' alcuni formati risultano "dichiarati" e non "verificati"? =

Perche' la libreria sostiene di supportarli ma la prova di codifica e' fallita. Quei formati restano disattivati di proposito: e' la situazione che genera file corrotti senza alcun errore visibile.

= AVIF viene generato ma non consegnato. Perche'? =

Molti server hanno un elenco di tipi MIME anteriore ad AVIF e consegnano i file `.avif` come `application/octet-stream`. In modalita' `<picture>` il browser sceglie la sorgente AVIF fidandosi dell'attributo `type`, poi non riesce a decodificarla e su Firefox e Safari non ripiega sull'immagine originale. Il plugin lo verifica con una richiesta reale e aggiunge una riga `AddType` al `.htaccess`; se non puo' scriverla, preferisce non consegnare AVIF.

== Changelog ==

= 1.0.0 =
* Prima versione.
