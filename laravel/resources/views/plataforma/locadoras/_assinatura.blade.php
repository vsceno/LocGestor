{{-- Plano, mensalidade e carência de criar e editar. $valores é o que já está gravado (ou o padrão, na criação);
     depois de um erro, o que foi digitado tem prioridade. --}}
<div class="grid gap-4">
    <label class="block"><span class="text-sm font-medium">Plano <span class="text-red-500">*</span></span>
        <select name="plano_id" required class="campo mt-1.5">
            <option value="">— escolha —</option>
            @foreach ($planos as $p)
                <option value="{{ $p->id }}" @selected(old('plano_id', $valores['plano_id'] ?? '') === $p->id)>
                    {{ $p->nome }} — {{ moeda($p->preco_mensal) }}/mês, até {{ $p->limite_usuarios }} usuário(s)
                </option>
            @endforeach
        </select>
    </label>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block"><span class="text-sm font-medium">Próximo vencimento</span>
            <input type="date" name="vencimento" value="{{ old('vencimento', $valores['vencimento'] ?? '') }}" class="campo mt-1.5">
            <span class="mt-1 block text-xs text-zinc-500">Vazio = sem cobrança (conta interna ou cortesia).</span>
        </label>
        <label class="block"><span class="text-sm font-medium">Dias de carência <span class="text-red-500">*</span></span>
            <input type="number" name="carencia_dias" value="{{ old('carencia_dias', $valores['carencia_dias'] ?? 5) }}" min="0" max="60" required class="campo mt-1.5">
            <span class="mt-1 block text-xs text-zinc-500">Dias após o vencimento antes de suspender o acesso.</span>
        </label>
    </div>
    @isset($valores['limite_usuarios'])
        <label class="block"><span class="text-sm font-medium">Limite de usuários</span>
            <input type="number" name="limite_usuarios" value="{{ old('limite_usuarios') }}" min="1" max="1000"
                   placeholder="{{ $valores['limite_usuarios'] }} (atual)" class="campo mt-1.5">
            <span class="mt-1 block text-xs text-zinc-500">Vazio: o do plano escolhido. Preencha só para um limite especial.</span>
        </label>
    @endisset
</div>
