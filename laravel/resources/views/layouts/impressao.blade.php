{{--
    Layout das páginas de impressão A4 (recibo, contrato, fatura, romaneio, vistoria): a mesma folha e o mesmo CSS
    (print.css) das páginas antigas. $titulo vai para a aba; $voltar é para onde o botão Voltar leva. Essas páginas
    abrem em aba nova, sem histórico, por isso o botão é um link.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo }}</title>
{{-- ?v= muda quando o arquivo muda: o navegador não usa a versão antiga do cache (igual ao asset() antigo) --}}
<link rel="stylesheet" href="{{ config('locgestor.legado_url') }}/assets/css/print.css?v={{ (int) @filemtime(base_path('../assets/css/print.css')) }}">
</head>
<body>
<div class="barra-impressao">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="{{ $voltar }}">Voltar</a>
    @yield('barra')
</div>
<div class="folha">
    @yield('folha')
</div>
</body>
</html>
