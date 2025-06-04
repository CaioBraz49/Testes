<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seu Título</title> <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

</head>


<header class="py-3 header-custom-light-purple" style="height: 100px;">
  <div class="container d-flex justify-content-between align-items-center">
    <!-- Logo à esquerda -->
    <a href="/">
      <img src="../img/logo.png" alt="Logo" style="height: 80px;">
    </a>
    
    <!-- Usuário à direita -->
    <div class="dropdown">
      <button class="btn btn-link text-decoration-none dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <i class="bi bi-person-fill"></i> <?php echo $_SESSION['user_nome']; ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#mudarSenhaModal">Mudar senha</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="../includes/logout.php">Sair</a></li>
      </ul>
    </div>
  </div>
</header>

<!-- Modal para Mudança de Senha -->
<div class="modal fade" id="mudarSenhaModal" tabindex="-1" aria-labelledby="mudarSenhaModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mudarSenhaModalLabel">Alterar Senha Numérica</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="formSenha">
          <div class="mb-3">
            <label for="senhaAtual" class="form-label">Senha Atual</label>
            <input type="password" class="form-control" id="senhaAtual" pattern="[0-9]*" inputmode="numeric" required>
          </div>
          <div class="mb-3">
            <label for="novaSenha" class="form-label">Nova Senha (6 dígitos)</label>
            <input type="password" class="form-control" id="novaSenha" pattern="[0-9]{6}" inputmode="numeric" maxlength="6" required>
            <div class="form-text">A senha deve conter exatamente 6 números</div>
          </div>
          <div class="mb-3">
            <label for="confirmarSenha" class="form-label">Confirmar Nova Senha</label>
            <input type="password" class="form-control" id="confirmarSenha" pattern="[0-9]{6}" inputmode="numeric" maxlength="6" required>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="salvarSenha">Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Scripts -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<script>
document.addEventListener('DOMContentLoaded', function() {
  const feedbackToastEl = document.getElementById('feedbackToast');
  let feedbackToastInstance; // Renomeado para clareza
  if (feedbackToastEl) {
    feedbackToastInstance = new bootstrap.Toast(feedbackToastEl);
  }

  const salvarSenhaButton = document.getElementById('salvarSenha');
  if (salvarSenhaButton) {
    salvarSenhaButton.addEventListener('click', async function() {
      const senhaAtualEl = document.getElementById('senhaAtual');
      const novaSenhaEl = document.getElementById('novaSenha');
      const confirmarSenhaEl = document.getElementById('confirmarSenha');

      // Verifique se os elementos do formulário existem antes de acessar .value
      if (!senhaAtualEl || !novaSenhaEl || !confirmarSenhaEl) {
          console.error("Elementos do formulário de mudança de senha não encontrados.");
          if (feedbackToastInstance) showFeedback('Erro interno no formulário.', 'danger');
          return;
      }

      const senhaAtual = senhaAtualEl.value;
      const novaSenha = novaSenhaEl.value;
      const confirmarSenha = confirmarSenhaEl.value;
      
      // Validações do cliente
      if(!/^\d+$/.test(senhaAtual)) {
        if (feedbackToastInstance) showFeedback('A senha atual deve conter apenas números.', 'danger');
        return;
      }
      
      if(novaSenha.length !== 6 || !/^\d+$/.test(novaSenha)) {
        if (feedbackToastInstance) showFeedback('A nova senha deve conter exatamente 6 dígitos numéricos.', 'danger');
        return;
      }
      
      if(novaSenha !== confirmarSenha) {
        if (feedbackToastInstance) showFeedback('As senhas não coincidem.', 'danger');
        return;
      }
      
      try {
        const response = await fetch('../includes/change_password.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ senhaAtual: senhaAtual, novaSenha: novaSenha })
        });
        
        const data = await response.json();
        
        if(data.success) {
          if (feedbackToastInstance) showFeedback('Senha alterada com sucesso!', 'success');
          setTimeout(() => {
            const mudarSenhaModalEl = document.getElementById('mudarSenhaModal');
            if (mudarSenhaModalEl) {
                const modal = bootstrap.Modal.getInstance(mudarSenhaModalEl);
                if (modal) modal.hide();
            }
            const formSenhaEl = document.getElementById('formSenha');
            if (formSenhaEl) formSenhaEl.reset();
          }, 1000);
        } else {
          if (feedbackToastInstance) showFeedback(data.message || 'Erro ao alterar senha.', 'danger');
        }
      } catch (error) {
        if (feedbackToastInstance) showFeedback('Erro na comunicação com o servidor.', 'danger');
        console.error('Erro ao mudar senha:', error);
      }
    });
  }
  
  // Permite apenas números nos campos de senha
  document.querySelectorAll('#mudarSenhaModal input[type="password"]').forEach(input => {
    input.addEventListener('input', function() {
      this.value = this.value.replace(/\D/g, '');
    });
  });
  
  // Função para exibir feedback
  function showFeedback(message, type) {
      if (!feedbackToastInstance || !feedbackToastEl) { // Verifica a instância e o elemento
          console.warn("Tentativa de mostrar feedback, mas a instância do Toast ou o elemento base não existe.");
          alert(message); // Fallback
          return;
      }

      const toastBody = feedbackToastEl.querySelector('.toast-body'); // Pega o corpo do toast a partir do elemento base

      if (toastBody) { // Verifica se o corpo do toast foi encontrado
          // Limpa classes de cor anteriores de feedbackToastEl
          feedbackToastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-white', 'text-dark');

          let bgColorClass = `bg-${type}`;
          let textColorClass = 'text-white'; // Padrão para fundos escuros

          // Ajusta a cor do texto para fundos mais claros do Bootstrap
          if (type === 'warning' || type === 'light' || type === 'info' || type === 'white' /* se você tiver um tipo 'white' */) {
              textColorClass = 'text-dark';
          }

          feedbackToastEl.classList.add(bgColorClass, textColorClass);
          toastBody.textContent = message;
          feedbackToastInstance.show();
      } else {
          console.error("Elemento .toast-body não encontrado dentro de #feedbackToast.");
          alert(message); // Fallback se a estrutura interna do toast estiver quebrada
      }
  }
});
</script>