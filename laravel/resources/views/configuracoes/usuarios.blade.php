@php
    $hoje = now()->toDateString();
    // Após erro, o que foi digitado; senão, o que está gravado no usuário em edição
    $u = fn (string $campo, $padrao = '') => old($campo, $editar->$campo ?? $padrao);
@endphp
@extends('layouts.app', ['titulo' => 'Configurações', 'ativo' => 'config'])

@section('subtitulo', 'Quem acessa a locadora. Cada usuário entra com o próprio login e senha.')

@section('conteudo')
@include('configuracoes._abas', ['ativa' => 'config.usuarios'])

@error('usuario')
    <div role="alert" class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">
        <x-icone nome="alerta" class="mt-0.5 size-5" /><div><p class="font-medium">O usuário não foi salvo.</p><p class="mt-0.5">{{ $message }}</p></div>
    </div>
@enderror

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <section class="cartao overflow-hidden xl:col-span-2">
        <div class="cartao-cabecalho">
            <h2 class="cartao-titulo">Usuários</h2>
            <span class="{{ $ativos >= $limite ? 'selo-amarelo' : 'selo-verde' }}">{{ numero($ativos) }} de {{ numero($limite) }} ativos (plano {{ $plano }})</span>
        </div>
        <div class="overflow-x-auto">
            <table class="tabela">
                <thead><tr><th>Nome</th><th>Login</th><th>Perfil</th><th>Situação</th><th>Último acesso</th><th class="w-px"></th></tr></thead>
                <tbody>
                @foreach ($usuarios as $x)
                    <tr>
                        <td class="font-medium text-zinc-900 dark:text-zinc-100">{{ $x->nome }}@if ($x->id === session('usuario.id')) <span class="text-zinc-500">(você)</span>@endif</td>
                        <td><code class="font-mono text-xs">{{ $x->login }}</code></td>
                        <td>{{ $x->perfil === 'admin' ? 'Administrador' : 'Operador' }}</td>
                        <td>@if ($x->ativo)<span class="selo-verde">ativo</span>@else<span class="selo-cinza">inativo</span>@endif</td>
                        <td class="whitespace-nowrap tabular-nums">{{ $x->ultimo_acesso ? data_br($x->ultimo_acesso, true) : 'nunca' }}</td>
                        <td><a class="btn-secundario" href="{{ route('config.usuarios', ['id' => $x->id]) }}">Editar</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="cartao self-start">
        <div class="cartao-cabecalho"><h2 class="cartao-titulo">{{ $editar ? 'Editar ' . $editar->nome : 'Novo usuário' }}</h2></div>
        <form method="post" action="{{ $editar ? route('config.usuarios.update', $editar->id) : route('config.usuarios.store') }}"
              autocomplete="off" class="grid gap-4 p-5">
            @csrf
            @if ($editar) @method('PUT') @endif

            <label class="block"><span class="text-sm font-medium">Nome <span class="text-red-500">*</span></span>
                <input type="text" name="nome" value="{{ $u('nome') }}" required maxlength="100" class="campo mt-1.5"></label>
            <label class="block"><span class="text-sm font-medium">Login <span class="text-red-500">*</span></span>
                <input type="text" name="login" value="{{ $u('login') }}" required maxlength="40" pattern="[a-z0-9._\-]{3,40}" autocapitalize="none"
                       class="campo mt-1.5 font-mono"></label>
            <label class="block"><span class="text-sm font-medium">Perfil <span class="text-red-500">*</span></span>
                <select name="perfil" class="campo mt-1.5">
                    <option value="operador" @selected($u('perfil', 'operador') === 'operador')>Operador</option>
                    <option value="admin" @selected($u('perfil', 'operador') === 'admin')>Administrador</option>
                </select>
                <span class="mt-1 block text-xs text-zinc-500">O administrador também altera a empresa, os usuários e exclui lançamentos do caixa.</span></label>
            <label class="block"><span class="text-sm font-medium">{{ $editar ? 'Nova senha (deixe em branco para manter)' : 'Senha' }}
                    @unless ($editar)<span class="text-red-500">*</span>@endunless</span>
                <input type="password" name="senha" minlength="8" autocomplete="new-password" @unless ($editar) required @endunless class="campo mt-1.5">
                <span class="mt-1 block text-xs text-zinc-500">Mínimo de 8 caracteres. Quem recebe a senha troca no primeiro acesso.</span></label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="ativo" value="1" @checked(session()->hasOldInput() ? old('ativo') : ($editar ? $editar->ativo : true))>
                Ativo
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primario">Salvar</button>
                @if ($editar)<a class="btn-secundario" href="{{ route('config.usuarios') }}">Novo usuário</a>@endif
            </div>
        </form>
    </section>
</div>
@endsection
