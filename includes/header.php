<header class="py-3 header-custom-light-purple" style="height: 100px;">
  <div class="container d-flex justify-content-between align-items-center">
    <!-- Logo à esquerda -->
    <a href="/">
      <img src="../img/logo.png" alt="Logo" style="height: 80px;">
    </a>
    
    <!-- Usuário à direita -->
    <div class="dropdown">
      <button class="btn btn-link text-decoration-none dropdown-toggle" type="button" data-bs-toggle="dropdown">
        <i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($_SESSION['user_nome']); ?>
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

<!-- Toast para feedback -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
  <div id="feedbackToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
      <strong class="me-auto">Sistema</strong>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
    <div class="toast-body" id="toastMessage"></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const feedbackToast = new bootstrap.Toast(document.getElementById('feedbackToast'));
  
  // Validação e envio do formulário
  document.getElementById('salvarSenha').addEventListener('click', async function() {
    const senhaAtual = document.getElementById('senhaAtual').value;
    const novaSenha = document.getElementById('novaSenha').value;
    const confirmarSenha = document.getElementById('confirmarSenha').value;
    
    // Validações do cliente
    if(!/^\d+$/.test(senhaAtual)) {
      showFeedback('A senha atual deve conter apenas números', 'danger');
      return;
    }
    
    if(novaSenha.length !== 6 || !/^\d+$/.test(novaSenha)) {
      showFeedback('A nova senha deve conter exatamente 6 dígitos numéricos', 'danger');
      return;
    }
    
    if(novaSenha !== confirmarSenha) {
      showFeedback('As senhas não coincidem', 'danger');
      return;
    }
    
    try {
      // Envia para o servidor via AJAX
      const response = await fetch('../includes/change_password.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          senhaAtual: senhaAtual,
          novaSenha: novaSenha
        })
      });
      
      const data = await response.json();
      
      if(data.success) {
        showFeedback('Senha alterada com sucesso!', 'success');
        // Fecha o modal após 1 segundo
        setTimeout(() => {
          const modal = bootstrap.Modal.getInstance(document.getElementById('mudarSenhaModal'));
          modal.hide();
          // Limpa o formulário
          document.getElementById('formSenha').reset();
        }, 1000);
      } else {
        showFeedback(data.message || 'Erro ao alterar senha', 'danger');
      }
    } catch (error) {
      showFeedback('Erro na comunicação com o servidor', 'danger');
      console.error('Erro:', error);
    }
  });
  
  // Permite apenas números nos campos de senha
  document.querySelectorAll('input[type="password"]').forEach(input => {
    input.addEventListener('input', function() {
      this.value = this.value.replace(/\D/g, '');
    });
  });
  
  // Função para exibir feedback
  function showFeedback(message, type) {
    const toast = document.getElementById('feedbackToast');
    toast.classList.remove('bg-success', 'bg-danger');
    toast.classList.add(`bg-${type}`);
    
    document.getElementById('toastMessage').textContent = message;
    feedbackToast.show();
  }
});
</script>