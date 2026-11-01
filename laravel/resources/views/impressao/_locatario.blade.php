{{-- Bloco do locatário nas páginas de impressão (locatario_impressao() do sistema antigo). $c: a linha do cliente --}}
@php $l = \App\Support\Documentos::locatario($c); @endphp
<h2>Locatário</h2>
<table>
    <tr><th style="width:22%">Nome / razão social</th><td colspan="3">{{ $l['nome'] }}</td></tr>
    <tr>
        <th>{{ $l['docRotulo'] }}</th><td>{{ $l['doc'] }}</td>
        <th style="width:18%">{{ $l['ieRotulo'] }}</th><td>{{ $l['ie'] }}</td>
    </tr>
    <tr><th>Endereço</th><td colspan="3">{{ $l['endereco'] }}</td></tr>
    <tr>
        <th>Telefone</th>
        <td>@foreach ($l['telefones'] as $tel)<span class="nowrap">{{ $tel }}</span>@if (! $loop->last) · @endif @endforeach</td>
        <th>E-mail</th><td>{{ $l['email'] }}</td>
    </tr>
</table>
