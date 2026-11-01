{{-- Bloco "Pague com PIX" (pix_bloco() do sistema antigo). O QR Code e o copia e cola são desenhados por pix.js. --}}
<div class="pix" data-pix="{{ $pix['payload'] }}">
    <div class="pix-qr" role="img" aria-label="QR Code PIX de {{ moeda($pix['valor']) }}"></div>
    <div class="pix-info">
        <strong>Pague com PIX: {{ moeda($pix['valor']) }}</strong>
        <span class="pix-ajuda">Aponte a câmera do app do banco para o QR Code ou use o PIX Copia e Cola:</span>
        <textarea class="pix-codigo" readonly rows="3" aria-label="Código PIX copia e cola">{{ $pix['payload'] }}</textarea>
        <button type="button" class="btn btn-sec btn-peq pix-copiar">Copiar código</button>
    </div>
</div>
