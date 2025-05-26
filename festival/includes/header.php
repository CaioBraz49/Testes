
<header class="bg-light py-3" style="height: 100px;">
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Validação do formulário
  document.getElementById('salvarSenha').addEventListener('click', function() {
    const senhaAtual = document.getElementById('senhaAtual').value;
    const novaSenha = document.getElementById('novaSenha').value;
    const confirmarSenha = document.getElementById('confirmarSenha').value;
    
    // Verifica se as senhas são numéricas
    if(!/^\d+$/.test(senhaAtual) {
      alert('A senha atual deve conter apenas números');
      return;
    }
    
    if(novaSenha.length !== 6 || !/^\d+$/.test(novaSenha)) {
      alert('A nova senha deve conter exatamente 6 dígitos numéricos');
      return;
    }
    
    if(novaSenha !== confirmarSenha) {
      alert('As senhas não coincidem');
      return;
    }
    
    // Aqui você pode adicionar a lógica para enviar ao servidor
    alert('Senha alterada com sucesso!');
    
    // Fecha o modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('mudarSenhaModal'));
    modal.hide();
  });
  
  // Permite apenas números nos campos de senha
  document.querySelectorAll('input[type="password"]').forEach(input => {
    input.addEventListener('input', function() {
      this.value = this.value.replace(/\D/g, '');
    });
  });
});
</script>