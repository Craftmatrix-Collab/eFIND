// In your user registration route
app.post('/register', async (req, res) => {
  const { email, name } = req.body;

  // 1. Save user to your database...
  // const user = await User.create({ email, name });

  // 2. Trigger the n8n workflow
  const n8nWebhookUrl = 'https://your-n8n-domain.com/webhook/your-unique-id';
  
  try {
    const response = await fetch(n8nWebhookUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        userEmail: email,
        userName: name,
        timestamp: new Date().toISOString(),
        // Include any other data your n8n workflow needs
      }),
    });

    if (response.ok) {
      console.log('n8n workflow triggered successfully!');
    } else {
      console.error('Failed to trigger n8n workflow');
    }
  } catch (error) {
    console.error('Error calling n8n webhook:', error);
  }

  // 3. Send response to the client
  res.send('Registration successful!');
});