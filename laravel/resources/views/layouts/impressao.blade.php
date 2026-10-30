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
<link rel="stylesheet" href="{{ asset_legado('assets/css/print.css') }}">
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
@yield('scripts')
</body>
</html>
