{{-- Instalação como aplicativo (PWA), como o sistema antigo: manifesto, ícones e o service worker (sw.js). Só na área da locadora. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="{{ isset($empresa) ? \App\Support\Identidade::resolver($empresa)['cor_principal'] : '#e8710a' }}">
<link rel="icon" type="image/png" href="{{ asset('assets/icons/icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-180.png') }}">
<script data-sw="{{ asset('sw.js') }}">
    // Só funciona em HTTPS (ou em localhost). Sem isso o sistema segue igual, só não instala.
    const sw = document.currentScript.dataset.sw;
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(sw).catch(function () {});
        });
    }
</script>
